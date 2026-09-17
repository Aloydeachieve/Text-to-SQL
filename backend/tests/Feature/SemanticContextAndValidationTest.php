<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SemanticMetric;
use App\Models\SemanticTableClassification;
use App\Models\SemanticTerm;
use App\Models\User;
use App\Services\SchemaRelevanceService;
use App\Services\SemanticContextService;
use App\Services\SqlSemanticValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticContextAndValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $admin;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Data Intelligence Corp']);
        $this->admin = User::create([
            'name' => 'Data Lead',
            'email' => 'lead@dataintelligence.com',
            'password' => 'password123',
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;
    }

    /**
     * Test 1: SemanticContextService builds rich prompt context for AI.
     */
    public function test_semantic_prompt_context_generation(): void
    {
        SemanticMetric::create([
            'company_id' => $this->company->id,
            'name' => 'Net Revenue',
            'slug' => 'net_revenue',
            'definition' => 'Total collected amount from completed customer payments.',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'filter_condition' => "status = 'completed'",
            'date_column' => 'paid_at',
            'is_active' => true,
            'is_source_of_truth' => true,
        ]);

        SemanticTableClassification::create([
            'company_id' => $this->company->id,
            'table_name' => 'staging_orders',
            'classification' => 'staging',
            'description' => 'Unverified ingestion cache',
        ]);

        $service = app(SemanticContextService::class);
        $context = $service->getSemanticPromptContext('What was our net revenue last month?', $this->company->id);

        $this->assertStringContainsString('BUSINESS SEMANTIC LAYER INTELLIGENCE', $context);
        $this->assertStringContainsString('Net Revenue', $context);
        $this->assertStringContainsString('payments', $context);
        $this->assertStringContainsString('amount', $context);
        $this->assertStringContainsString("status = 'completed'", $context);
        $this->assertStringContainsString('staging_orders', $context);
        $this->assertStringContainsString('WARNING: Do NOT use this table', $context);
    }

    /**
     * Test 2: SqlSemanticValidator detects staging source warning.
     */
    public function test_sql_semantic_validator_flags_staging_table(): void
    {
        SemanticTableClassification::create([
            'company_id' => $this->company->id,
            'table_name' => 'staging_orders',
            'classification' => 'staging',
        ]);

        $validator = app(SqlSemanticValidator::class);
        $result = $validator->validate(
            'Show me order sums',
            'SELECT SUM(total) FROM staging_orders',
            companyId: $this->company->id
        );

        $this->assertTrue($result['valid']);
        $this->assertEquals('MEDIUM', $result['semantic_confidence']);
        $this->assertNotEmpty($result['semantic_warnings']);
        $this->assertEquals('staging_source_warning', $result['semantic_warnings'][0]['type']);
        $this->assertStringContainsString('staging_orders', $result['semantic_warnings'][0]['message']);
    }

    /**
     * Test 3: SqlSemanticValidator flags missing required filter for canonical metric.
     */
    public function test_sql_semantic_validator_flags_missing_filter(): void
    {
        SemanticMetric::create([
            'company_id' => $this->company->id,
            'name' => 'Revenue',
            'slug' => 'revenue',
            'definition' => 'Total revenue from completed payments',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'filter_condition' => "status = 'completed'",
            'is_source_of_truth' => true,
        ]);

        $validator = app(SqlSemanticValidator::class);

        // Query missing "status = 'completed'"
        $result = $validator->validate(
            'What is our total revenue?',
            'SELECT SUM(amount) FROM payments',
            companyId: $this->company->id
        );

        $this->assertNotEmpty($result['semantic_warnings']);
        $warningTypes = array_column($result['semantic_warnings'], 'type');
        $this->assertContains('required_filter_missing', $warningTypes);
        $this->assertFalse($result['source_of_truth']);
    }

    /**
     * Test 4: SqlSemanticValidator validates compliant canonical query with HIGH confidence.
     */
    public function test_sql_semantic_validator_verifies_compliant_query(): void
    {
        SemanticMetric::create([
            'company_id' => $this->company->id,
            'name' => 'Revenue',
            'slug' => 'revenue',
            'definition' => 'Total revenue from completed payments',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'filter_condition' => "status = 'completed'",
            'is_source_of_truth' => true,
        ]);

        $validator = app(SqlSemanticValidator::class);

        // Fully compliant query
        $result = $validator->validate(
            'What is our total revenue?',
            "SELECT SUM(amount) FROM payments WHERE status = 'completed'",
            companyId: $this->company->id
        );

        $this->assertTrue($result['source_of_truth']);
        $this->assertEquals('HIGH', $result['semantic_confidence']);
        $this->assertEmpty($result['semantic_warnings']);
        $this->assertEquals('Revenue', $result['metric']);
    }

    /**
     * Test 5: SchemaRelevanceService utilizes company SemanticTerms.
     */
    public function test_schema_relevance_utilizes_company_terms(): void
    {
        SemanticTerm::create([
            'company_id' => $this->company->id,
            'term' => 'inventory',
            'target_type' => 'table',
            'target_name' => 'products',
        ]);

        $relevanceService = app(SchemaRelevanceService::class);
        $schema = [
            'tables' => [
                ['name' => 'orders', 'columns' => [['name' => 'id', 'type' => 'int']]],
                ['name' => 'products', 'columns' => [['name' => 'id', 'type' => 'int'], ['name' => 'title', 'type' => 'varchar']]],
            ],
            'relationships' => [],
        ];

        $res = $relevanceService->selectRelevantSchema('Check current inventory levels', $schema, $this->company->id);
        $this->assertContains('products', $res['matched_tables']);
    }

    /**
     * Test 6: Custom SQL query execution returns semantic warnings for staging tables.
     */
    public function test_custom_sql_endpoint_returns_semantic_intelligence(): void
    {
        SemanticTableClassification::create([
            'company_id' => $this->company->id,
            'table_name' => 'orders',
            'classification' => 'staging',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->adminToken}"])
            ->postJson('/api/v1/query', [
                'question' => 'Query on staging orders',
                'sql' => 'SELECT SUM(total_amount) FROM orders',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('semantic_validation.semantic_confidence', 'MEDIUM');

        $warnings = $response->json('semantic_validation.semantic_warnings');
        $this->assertNotEmpty($warnings);
        $this->assertEquals('staging_source_warning', $warnings[0]['type']);
    }

    /**
     * Test 7: SqlSemanticValidator detects archive table warning and metric source mismatch.
     */
    public function test_sql_semantic_validator_flags_archive_table_and_metric_source_mismatch(): void
    {
        SemanticTableClassification::create([
            'company_id' => $this->company->id,
            'table_name' => 'archived_orders',
            'classification' => 'archive',
        ]);

        SemanticMetric::create([
            'company_id' => $this->company->id,
            'name' => 'Revenue',
            'slug' => 'revenue',
            'definition' => 'Total revenue from completed payments',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'is_source_of_truth' => true,
        ]);

        $validator = app(SqlSemanticValidator::class);

        // Query using archived_orders instead of payments
        $result = $validator->validate(
            'What was our revenue?',
            'SELECT SUM(total) FROM archived_orders',
            companyId: $this->company->id
        );

        $this->assertNotEmpty($result['semantic_warnings']);
        $warningTypes = array_column($result['semantic_warnings'], 'type');
        $this->assertContains('archive_source_warning', $warningTypes);
        $this->assertContains('metric_source_mismatch', $warningTypes);
        $this->assertFalse($result['source_of_truth']);
    }

    /**
     * Test 8: Company concept terms trigger ambiguity clarification workflow.
     */
    public function test_company_concept_term_triggers_ambiguity_clarification(): void
    {
        SemanticTerm::create([
            'company_id' => $this->company->id,
            'term' => 'active customers',
            'target_type' => 'concept',
            'target_name' => 'active_customer_concept',
            'definition' => "Please specify how 'active customer' should be defined (e.g., ordered in last 30 days vs 90 days).",
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->adminToken}"])
            ->postJson('/api/v1/query', [
                'question' => 'How many active customers do we have?',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ambiguous', true);

        $this->assertStringContainsString('active customer', $response->json('clarification'));
    }
}
