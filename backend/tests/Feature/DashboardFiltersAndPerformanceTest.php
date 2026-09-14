<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DatabaseConnection;
use App\Models\SavedQuery;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\DashboardFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardFiltersAndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected User $userA;
    protected string $tokenA;

    protected Company $companyB;
    protected User $userB;
    protected string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Company A and User A
        $this->companyA = Company::create(['name' => 'Acme Corporation']);
        $this->userA = User::create([
            'name' => 'Alice Admin',
            'email' => 'alice@acme.com',
            'password' => 'Password123!',
            'company_id' => $this->companyA->id,
        ]);
        $this->tokenA = $this->userA->createToken('test-token-a')->plainTextToken;

        // Setup Company B and User B
        $this->companyB = Company::create(['name' => 'Beta Global']);
        $this->userB = User::create([
            'name' => 'Bob Beta',
            'email' => 'bob@beta.com',
            'password' => 'Password123!',
            'company_id' => $this->companyB->id,
        ]);
        $this->tokenB = $this->userB->createToken('test-token-b')->plainTextToken;
    }

    /**
     * Test 1: Date preset filter (e.g. last_30_days) applies PDO prepared statement bindings.
     */
    public function test_date_preset_filter_applies_parameterized_where_clause(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Sales KPI Dashboard',
        ]);

        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Orders List',
            'natural_language_question' => 'Show all orders',
            'sql' => 'SELECT id, customer_id, order_date, total_amount FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'Orders Overview',
            'position' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute", [
                'date_preset' => 'last_30_days',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'dashboard_id' => $dashboard->id,
                'active_filters' => [
                    'date_preset' => 'last_30_days',
                ],
            ]);

        $widgets = $response->json('widgets');
        $this->assertCount(1, $widgets);

        $widget = $widgets[0];
        $this->assertTrue($widget['success']);
        $this->assertTrue($widget['filter_applied']);
        $this->assertEquals('applied', $widget['filter_status']);
        $this->assertNotNull($widget['filter_message']);

        // Check SQL has parameterized placeholder '?' and NOT raw string concatenation
        $this->assertMatchesRegularExpression('/>=\s*\?\s*AND\s*.*<=\s*\?/i', $widget['sql']);
        $this->assertStringNotContainsString(">= '", $widget['sql']);
    }

    /**
     * Test 2: Custom date range filter with explicit date_from and date_to.
     */
    public function test_custom_date_range_filter_with_valid_dates(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Custom Range Dashboard',
        ]);

        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Orders Range',
            'natural_language_question' => 'Orders in January 2026',
            'sql' => 'SELECT id, order_date, total_amount FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'January Orders',
            'position' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute", [
                'date_preset' => 'custom',
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
            ]);

        $response->assertStatus(200);
        $widget = $response->json('widgets')[0];

        $this->assertTrue($widget['filter_applied']);
        $this->assertEquals('applied', $widget['filter_status']);
        $this->assertStringContainsString('2026-01-01', $widget['filter_message']);
        $this->assertStringContainsString('2026-01-31', $widget['filter_message']);
    }

    /**
     * Test 3: Date filter on query with existing WHERE clause properly wraps conditions.
     */
    public function test_date_filter_wraps_existing_where_conditions(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Filtered Orders Dashboard',
        ]);

        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'High Value Orders',
            'natural_language_question' => 'High value orders',
            'sql' => 'SELECT id, order_date, total_amount FROM orders WHERE total_amount > 100',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'High Value Orders',
            'position' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute", [
                'date_preset' => 'today',
            ]);

        $response->assertStatus(200);
        $widget = $response->json('widgets')[0];

        $this->assertTrue($widget['filter_applied']);
        // Verify that existing WHERE condition was wrapped: (total_amount > 100) AND (...)
        $this->assertMatchesRegularExpression('/WHERE\s*\(\s*total_amount > 100\s*\)\s*AND\s*\(/i', $widget['sql']);
    }

    /**
     * Test 4: Compound / UNION queries mark filter as unsupported without crashing.
     */
    public function test_compound_union_queries_mark_filter_unsupported_safely(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Union Dashboard',
        ]);

        $unionQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Union Query',
            'natural_language_question' => 'Union query',
            'sql' => 'SELECT id FROM orders UNION SELECT id FROM customers',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $unionQuery->id,
            'title' => 'Union Widget',
            'position' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute", [
                'date_preset' => 'last_7_days',
            ]);

        $response->assertStatus(200);
        $widget = $response->json('widgets')[0];

        $this->assertTrue($widget['success']);
        $this->assertFalse($widget['filter_applied']);
        $this->assertEquals('unsupported', $widget['filter_status']);
        $this->assertStringContainsString('UNION', $widget['filter_message']);
        // Query should have run unmodified
        $this->assertEquals($unionQuery->sql, $widget['sql']);
    }

    /**
     * Test 5: Invalid date ranges fail safely without crashing dashboard.
     */
    public function test_invalid_date_ranges_fail_safely(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Invalid Date Dashboard',
        ]);

        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Orders Query',
            'natural_language_question' => 'Orders',
            'sql' => 'SELECT id, order_date FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'Orders Widget',
            'position' => 0,
        ]);

        // date_from is AFTER date_to
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute", [
                'date_preset' => 'custom',
                'date_from' => '2026-12-31',
                'date_to' => '2026-01-01',
            ]);

        $response->assertStatus(200);
        $widget = $response->json('widgets')[0];

        $this->assertTrue($widget['success']);
        $this->assertFalse($widget['filter_applied']);
        $this->assertEquals('unsupported', $widget['filter_status']);
        $this->assertStringContainsString('must be before or equal to', $widget['filter_message']);
    }

    /**
     * Test 6: Short-lived caching stores results and returns cache_hit = true on subsequent requests.
     */
    public function test_short_lived_caching_and_bypass_cache_support(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Cache Test Dashboard',
        ]);

        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Cacheable Query',
            'natural_language_question' => 'Customer count',
            'sql' => 'SELECT count(*) as count FROM customers',
            'dialect' => 'mysql',
            'result_visualization_type' => 'metric',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'Count Widget',
            'position' => 0,
        ]);

        // First Execution: should be a cache miss
        $firstResponse = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute");

        $firstResponse->assertStatus(200);
        $firstWidget = $firstResponse->json('widgets')[0];
        $this->assertFalse($firstWidget['cache_hit']);

        // Second Execution (identical parameters): should be a cache hit
        $secondResponse = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute");

        $secondResponse->assertStatus(200);
        $secondWidget = $secondResponse->json('widgets')[0];
        $this->assertTrue($secondWidget['cache_hit']);

        // Third Execution with bypass_cache = true: must bypass cache and return fresh data
        $bypassResponse = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute", [
                'bypass_cache' => true,
            ]);

        $bypassResponse->assertStatus(200);
        $bypassWidget = $bypassResponse->json('widgets')[0];
        $this->assertFalse($bypassWidget['cache_hit']);
    }

    /**
     * Test 7: Cache keys are strictly tenant-isolated.
     */
    public function test_cache_is_strictly_tenant_isolated(): void
    {
        $cacheService = app(DashboardFilterService::class);
        $dashboardCache = app(DashboardCacheService::class);

        $keyA = $dashboardCache->getCacheKey(
            $this->companyA->id,
            null,
            42,
            '2026-09-10T12:00:00Z',
            ['date_preset' => 'last_7_days']
        );

        $keyB = $dashboardCache->getCacheKey(
            $this->companyB->id,
            null,
            42,
            '2026-09-10T12:00:00Z',
            ['date_preset' => 'last_7_days']
        );

        $this->assertNotEquals($keyA, $keyB);
        $this->assertStringContainsString("c_{$this->companyA->id}", $keyA);
        $this->assertStringContainsString("c_{$this->companyB->id}", $keyB);
    }

    /**
     * Test 8: Query interpretation and calculation/multiplication risks are preserved under filtered execution.
     */
    public function test_query_correctness_and_fanout_risks_preserved_with_filters(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Fanout Risk Dashboard',
        ]);

        // Query with potential fanout: orders JOIN order_items with aggregation on orders.total_amount
        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Fanout Query',
            'natural_language_question' => 'Orders and items total',
            'sql' => 'SELECT orders.id, SUM(orders.total_amount) as total FROM orders JOIN order_items ON orders.id = order_items.order_id GROUP BY orders.id',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'Fanout Widget',
            'position' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute", [
                'date_preset' => 'last_30_days',
            ]);

        $response->assertStatus(200);
        $widget = $response->json('widgets')[0];

        $this->assertTrue($widget['filter_applied']);
        $this->assertNotNull($widget['interpretation']);

        // Check grain and fanout risk detection preserved
        $interpretation = $widget['interpretation'];
        $this->assertArrayHasKey('grain', $interpretation);
        $this->assertArrayHasKey('multiplication_risk', $interpretation);
        $this->assertTrue($interpretation['multiplication_risk']['detected']);
    }

    /**
     * Test 9: CSV export returns RFC 4180 streaming response with active filters and formula sanitization.
     */
    public function test_csv_export_returns_streamed_csv_with_formula_sanitization(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Exportable Dashboard',
        ]);

        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Demo Orders',
            'natural_language_question' => 'Recent orders',
            'sql' => 'SELECT id, customer_id, total_amount FROM orders LIMIT 5',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'Recent Orders Widget',
            'position' => 0,
        ]);

        // Insert sample row to ensure CSV rows are rendered
        $cid = \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'name' => '=HYPERLINK("http://evil.com")',
            'email' => 'formula@example.com',
            'city' => 'Chicago',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('orders')->insert([
            'customer_id' => $cid,
            'order_date' => now()->toDateString(),
            'total_amount' => 99.99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->get("/api/v1/dashboards/{$dashboard->id}/export/csv?date_preset=last_7_days");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Dashboard Export', $content);
        $this->assertStringContainsString('Exportable Dashboard', $content);
        $this->assertStringContainsString('Recent Orders Widget', $content);
        $this->assertStringContainsString('id,customer_id,total_amount', $content);
        $this->assertStringContainsString('99.99', $content);
    }

    /**
     * Test 10: Executive Summary export returns structured JSON with metadata, filters, and calculation risks.
     */
    public function test_executive_summary_export_returns_structured_metadata_and_risks(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Board Summary Dashboard',
        ]);

        $query = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Board Metrics',
            'natural_language_question' => 'Customers',
            'sql' => 'SELECT count(*) as total_customers FROM customers',
            'dialect' => 'mysql',
            'result_visualization_type' => 'metric',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $query->id,
            'title' => 'Total Customers',
            'position' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/dashboards/{$dashboard->id}/export/summary?date_preset=this_month");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'dashboard' => [
                    'id' => $dashboard->id,
                    'name' => 'Board Summary Dashboard',
                    'company' => 'Acme Corporation',
                ],
                'summary_stats' => [
                    'total_widgets' => 1,
                    'successful_widgets' => 1,
                    'failed_widgets' => 0,
                ],
            ]);

        $data = $response->json();
        $this->assertArrayHasKey('widgets', $data);
        $this->assertCount(1, $data['widgets']);
        $this->assertArrayHasKey('calculation_risk', $data['widgets'][0]);
    }

    /**
     * Test 11: Cross-tenant export requests (CSV and Summary) return 404.
     */
    public function test_cross_tenant_export_requests_are_rejected(): void
    {
        $dashboardA = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Confidential Dashboard A',
        ]);

        // User B attempts to export Company A's CSV
        $csvResponse = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->get("/api/v1/dashboards/{$dashboardA->id}/export/csv");
        $csvResponse->assertStatus(404);

        // User B attempts to export Company A's Executive Summary
        $summaryResponse = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->getJson("/api/v1/dashboards/{$dashboardA->id}/export/summary");
        $summaryResponse->assertStatus(404);
    }
}
