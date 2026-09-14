<?php

namespace Tests\Feature;

use App\Services\DatabaseSchemaService;
use App\Services\QueryInterpretationService;
use Tests\TestCase;

class QueryInterpretationTest extends TestCase
{
    protected QueryInterpretationService $interpreter;
    protected array $schemaDetails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->interpreter = new QueryInterpretationService();
        $this->schemaDetails = app(DatabaseSchemaService::class)->getSchemaDetails();
    }

    /**
     * Test extracting joins and aliases.
     */
    public function test_extracts_joins_and_resolves_aliases(): void
    {
        $sql = "SELECT o.id, oi.unit_price FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.status = 'completed'";
        $result = $this->interpreter->interpret($sql, $this->schemaDetails);

        $this->assertEquals(['orders', 'order_items'], $result['tables']);
        $this->assertCount(1, $result['joins']);
        $this->assertEquals('orders.id → order_items.order_id', $result['joins'][0]);
        $this->assertContains("orders.status = 'completed'", $result['filters']);
    }

    /**
     * Test grain calculation for hierarchical tables (Order → Order Item).
     */
    public function test_determines_grain_for_hierarchical_tables(): void
    {
        $sql = "SELECT * FROM orders JOIN order_items ON orders.id = order_items.order_id";
        $result = $this->interpreter->interpret($sql, $this->schemaDetails);

        $this->assertEquals('Order → Order Item', $result['grain']);
    }

    /**
     * Test grain calculation for customers and orders (Customer → Order).
     */
    public function test_determines_grain_for_customers_and_orders(): void
    {
        $sql = "SELECT * FROM customers c JOIN orders o ON c.id = o.customer_id";
        $result = $this->interpreter->interpret($sql, $this->schemaDetails);

        $this->assertEquals('Customer → Order', $result['grain']);
    }

    /**
     * Test grain calculation when GROUP BY is present.
     */
    public function test_determines_grain_with_group_by(): void
    {
        $sql = "SELECT c.name, COUNT(o.id) FROM customers c JOIN orders o ON c.id = o.customer_id GROUP BY c.id";
        $result = $this->interpreter->interpret($sql, $this->schemaDetails);

        $this->assertStringContainsString('Customer Id (Grouped)', $result['grain']);
    }

    /**
     * Test detecting potential multiplication risk on SUM(orders.total) joined with order_items.
     */
    public function test_detects_potential_multiplication_risk_on_parent_aggregation(): void
    {
        $sql = "SELECT SUM(orders.total_amount) FROM orders JOIN order_items ON orders.id = order_items.order_id WHERE orders.status = 'completed'";
        $result = $this->interpreter->interpret($sql, $this->schemaDetails);

        $this->assertNotNull($result['multiplication_risk']);
        $this->assertTrue($result['multiplication_risk']['detected']);
        $this->assertStringContainsString('order_items contains multiple rows per order', $result['multiplication_risk']['warning']);
        $this->assertStringContainsString('inflated totals', $result['multiplication_risk']['details']);
        $this->assertContains('SUM(orders.total_amount)', $result['aggregations']);
    }

    /**
     * Test detecting multiplication risk when aliases are used (SUM(o.total_amount)).
     */
    public function test_detects_multiplication_risk_with_table_aliases(): void
    {
        $sql = "SELECT SUM(o.total_amount) FROM orders o JOIN order_items oi ON o.id = oi.order_id";
        $result = $this->interpreter->interpret($sql, $this->schemaDetails);

        $this->assertNotNull($result['multiplication_risk']);
        $this->assertTrue($result['multiplication_risk']['detected']);
        $this->assertStringContainsString('order_items contains multiple rows per order', $result['multiplication_risk']['warning']);
    }

    /**
     * Test NO multiplication risk when aggregating child table columns (SUM(order_items.total_price)).
     */
    public function test_no_multiplication_risk_when_aggregating_child_table(): void
    {
        $sql = "SELECT SUM(order_items.total_price) FROM orders JOIN order_items ON orders.id = order_items.order_id";
        $result = $this->interpreter->interpret($sql, $this->schemaDetails);

        $this->assertNull($result['multiplication_risk']);
    }

    /**
     * Test API endpoint /api/v1/query returns full query interpretation.
     */
    public function test_api_query_returns_query_interpretation_and_grain(): void
    {
        $response = $this->postJson('/api/v1/query', [
            'question' => 'What is the monthly revenue?',
            'sql' => "SELECT SUM(orders.total_amount) as total FROM orders JOIN order_items ON orders.id = order_items.order_id WHERE orders.order_date >= '2026-01-01'",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'semantic_validation' => [
                    'valid',
                    'tables',
                    'joins',
                    'grain',
                    'filters',
                    'aggregations',
                    'multiplication_risk' => [
                        'detected',
                        'warning',
                        'details',
                        'recommendation',
                    ],
                ],
            ]);

        $semantic = $response->json('semantic_validation');
        $this->assertEquals('Order → Order Item', $semantic['grain']);
        $this->assertContains('orders.id → order_items.order_id', $semantic['joins']);
        $this->assertTrue($semantic['multiplication_risk']['detected']);
        $this->assertEquals('order_items contains multiple rows per order.', $semantic['multiplication_risk']['warning']);
    }
}
