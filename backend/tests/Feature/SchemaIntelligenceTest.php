<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Models\User;
use App\Services\AiSqlManager;
use App\Services\Ai\AiSqlResponse;
use App\Services\DatabaseSchemaService;
use App\Services\QuestionAmbiguityService;
use App\Services\SchemaIntrospectionService;
use App\Services\SchemaRelevanceService;
use App\Services\SqlSchemaValidator;
use App\Services\SqlSemanticValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;
    protected string $token;
    protected DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Intelligence Corp']);
        $this->user = User::create([
            'name' => 'Ian Intelligence',
            'email' => 'ian@intelligence.com',
            'password' => 'Password123!',
            'company_id' => $this->company->id,
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->connection = DatabaseConnection::create([
            'company_id' => $this->company->id,
            'name' => 'Primary Intelligence MySQL',
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'text_to_sql_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'status' => 'connected',
        ]);
    }

    /**
     * Test 1: Schema normalization includes consistent column attributes and relationships.
     */
    public function test_schema_normalization_includes_consistent_column_and_relationship_attributes(): void
    {
        $schemaService = app(DatabaseSchemaService::class);
        $details = $schemaService->getSchemaDetails();

        $this->assertArrayHasKey('tables', $details);
        $this->assertArrayHasKey('relationships', $details);

        foreach ($details['tables'] as $table) {
            $this->assertArrayHasKey('name', $table);
            $this->assertArrayHasKey('columns', $table);

            foreach ($table['columns'] as $col) {
                $this->assertArrayHasKey('name', $col);
                $this->assertArrayHasKey('type', $col);
                $this->assertArrayHasKey('primary', $col);
                $this->assertArrayHasKey('foreign', $col);
                $this->assertArrayHasKey('nullable', $col);
                $this->assertArrayHasKey('referenced_table', $col);
                $this->assertArrayHasKey('referenced_column', $col);
            }
        }

        foreach ($details['relationships'] as $rel) {
            $this->assertArrayHasKey('from', $rel);
            $this->assertArrayHasKey('to', $rel);
            $this->assertArrayHasKey('from_table', $rel);
            $this->assertArrayHasKey('from_column', $rel);
            $this->assertArrayHasKey('to_table', $rel);
            $this->assertArrayHasKey('to_column', $rel);
            $this->assertArrayHasKey('label', $rel);
        }
    }

    /**
     * Test 2: System and internal framework tables are excluded from introspection.
     */
    public function test_system_table_exclusion_filters_internal_tables(): void
    {
        $introspection = app(SchemaIntrospectionService::class);

        // Internal framework tables should be excluded
        $this->assertTrue($introspection->isExcludedTable('migrations', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('personal_access_tokens', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('failed_jobs', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('cache', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('sessions', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('telescope_entries', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('pulse_values', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('sys', 'mysql'));
        $this->assertTrue($introspection->isExcludedTable('spatial_ref_sys', 'pgsql'));
        $this->assertTrue($introspection->isExcludedTable('pg_stat_activity', 'pgsql'));

        // Legitimate application tables should NOT be excluded
        $this->assertFalse($introspection->isExcludedTable('customers', 'mysql'));
        $this->assertFalse($introspection->isExcludedTable('orders', 'mysql'));
        $this->assertFalse($introspection->isExcludedTable('products', 'mysql'));
        $this->assertFalse($introspection->isExcludedTable('order_items', 'mysql'));
        $this->assertFalse($introspection->isExcludedTable('invoices', 'pgsql'));
    }

    /**
     * Test 3: Schema relevance engine performs direct table and column name matching.
     */
    public function test_schema_relevance_direct_table_and_column_matching(): void
    {
        $relevance = app(SchemaRelevanceService::class);
        $schemaService = app(DatabaseSchemaService::class);
        $schema = $schemaService->getSchemaDetails();

        $result = $relevance->selectRelevantSchema('Find customer email and city', $schema);

        $this->assertTrue($result['is_subset']);
        $this->assertContains('customers', $result['matched_tables']);

        $tableNames = array_map(fn($t) => $t['name'], $result['schema']['tables']);
        $this->assertContains('customers', $tableNames);
    }

    /**
     * Test 4: Schema relevance engine resolves business terminology mappings.
     */
    public function test_schema_relevance_business_term_mapping(): void
    {
        $relevance = app(SchemaRelevanceService::class);
        $schemaService = app(DatabaseSchemaService::class);
        $schema = $schemaService->getSchemaDetails();

        // "top-selling" should match products and order_items
        $result = $relevance->selectRelevantSchema('What are our top-selling items?', $schema);

        $tableNames = array_map(fn($t) => $t['name'], $result['schema']['tables']);
        $this->assertContains('products', $tableNames);
        $this->assertContains('order_items', $tableNames);
    }

    /**
     * Test 5: In-memory relational graph traversal discovers bridge tables between matched endpoints.
     */
    public function test_schema_relevance_in_memory_graph_bridge_table_connection(): void
    {
        $relevance = app(SchemaRelevanceService::class);
        $schemaService = app(DatabaseSchemaService::class);
        $schema = $schemaService->getSchemaDetails();

        // Question matches customers and products directly
        $result = $relevance->selectRelevantSchema('Which customers and products?', $schema);

        $this->assertContains('customers', $result['matched_tables']);
        $this->assertContains('products', $result['matched_tables']);

        // Bridge tables connecting customers and products must be included
        $this->assertContains('orders', $result['bridge_tables']);
        $this->assertContains('order_items', $result['bridge_tables']);

        $tableNames = array_map(fn($t) => $t['name'], $result['schema']['tables']);
        $this->assertContains('customers', $tableNames);
        $this->assertContains('orders', $tableNames);
        $this->assertContains('order_items', $tableNames);
        $this->assertContains('products', $tableNames);
    }

    /**
     * Test 6: Fallback safety - unmatchable questions fallback to full normalized schema, never empty.
     */
    public function test_schema_relevance_fallback_to_full_schema_when_no_match(): void
    {
        $relevance = app(SchemaRelevanceService::class);
        $schemaService = app(DatabaseSchemaService::class);
        $schema = $schemaService->getSchemaDetails();

        $result = $relevance->selectRelevantSchema('Hello there, tell me something random', $schema);

        $this->assertFalse($result['is_subset']);
        $this->assertNotEmpty($result['schema']['tables']);
        $this->assertCount(count($schema['tables']), $result['schema']['tables']);
    }

    /**
     * Test 7: Large schema protection caps tables and columns without destroying PK/FK relationships.
     */
    public function test_schema_relevance_large_schema_protection(): void
    {
        $relevance = app(SchemaRelevanceService::class);

        // Build a simulated large schema with 25 tables, each having 40 columns
        $simulatedTables = [];
        for ($i = 1; $i <= 25; $i++) {
            $cols = [
                ['name' => 'id', 'type' => 'int', 'primary' => true, 'foreign' => false, 'nullable' => false],
                ['name' => 'parent_id', 'type' => 'int', 'primary' => false, 'foreign' => true, 'nullable' => true, 'referenced_table' => 'table_1', 'referenced_column' => 'id'],
            ];
            for ($c = 3; $c <= 40; $c++) {
                $cols[] = ['name' => "column_{$c}", 'type' => 'varchar', 'primary' => false, 'foreign' => false, 'nullable' => true];
            }
            $simulatedTables[] = [
                'name' => "table_{$i}",
                'columns' => $cols,
            ];
        }

        $largeSchema = [
            'tables' => $simulatedTables,
            'relationships' => [
                ['from' => 'table_2.parent_id', 'to' => 'table_1.id', 'label' => 'ref'],
            ]
        ];

        $protected = $relevance->applyLargeSchemaProtection($largeSchema, 'Show table_1 data');

        $maxTables = (int) config('schema.max_tables_in_context', 15);
        $maxCols = (int) config('schema.max_columns_per_table', 30);

        $this->assertLessThanOrEqual($maxTables, count($protected['tables']));

        foreach ($protected['tables'] as $table) {
            $this->assertLessThanOrEqual($maxCols, count($table['columns']));
            // Primary key must be preserved
            $hasPk = collect($table['columns'])->contains('primary', true);
            $this->assertTrue($hasPk, "Table {$table['name']} lost primary key during large schema protection.");
        }
    }

    /**
     * Test 8: QuestionAmbiguityService identifies ambiguous natural language questions.
     */
    public function test_question_ambiguity_service_detects_ambiguous_queries(): void
    {
        $ambiguityService = app(QuestionAmbiguityService::class);

        $ambiguous1 = $ambiguityService->evaluateAmbiguity('How are sales doing?');
        $this->assertTrue($ambiguous1['ambiguous']);
        $this->assertNotEmpty($ambiguous1['suggestions']);
        $this->assertStringContainsString('sales', $ambiguous1['clarification']);

        $ambiguous2 = $ambiguityService->evaluateAmbiguity('Who are our best customers?');
        $this->assertTrue($ambiguous2['ambiguous']);
        $this->assertNotEmpty($ambiguous2['suggestions']);

        // Specific clear questions must NOT be flagged as ambiguous
        $clear1 = $ambiguityService->evaluateAmbiguity('How many customers do we have?');
        $this->assertFalse($clear1['ambiguous']);

        $clear2 = $ambiguityService->evaluateAmbiguity('Which customers placed the most orders?');
        $this->assertFalse($clear2['ambiguous']);

        $clear3 = $ambiguityService->evaluateAmbiguity('What is our total revenue by month?');
        $this->assertFalse($clear3['ambiguous']);
    }

    /**
     * Test 9: Query API returns structured clarification response for ambiguous questions.
     */
    public function test_query_api_returns_clarification_for_ambiguous_question(): void
    {
        $response = $this->postJson('/api/v1/query', [
            'question' => 'How are our sales?',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'question',
            'ambiguous',
            'clarification',
            'suggestions',
        ]);

        $this->assertTrue($response->json('ambiguous'));
        $this->assertNotEmpty($response->json('suggestions'));
        $this->assertNull($response->json('sql'));
        $this->assertFalse($response->json('execution.success'));
    }

    /**
     * Test 10: Controlled SQL regeneration retry recovers from schema validation failure.
     */
    public function test_controlled_sql_regeneration_retry_recovers_on_schema_error(): void
    {
        // Mock AI Manager to simulate initial invalid column followed by successful retry
        $mockAi = $this->createMock(AiSqlManager::class);

        // Initial call generates invalid column 'product_name'
        $mockAi->expects($this->once())
            ->method('generateSql')
            ->willReturn(new AiSqlResponse(
                success: true,
                sql: 'SELECT product_name FROM products',
                confidence: 0.85,
                explanation: 'Select product name'
            ));

        // Retry call receives error and corrects to 'name'
        $mockAi->expects($this->once())
            ->method('generateSqlWithCorrection')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo('SELECT product_name FROM products'),
                $this->stringContains('Unknown column reference')
            )
            ->willReturn(new AiSqlResponse(
                success: true,
                sql: 'SELECT name FROM products',
                confidence: 0.95,
                explanation: 'Corrected to valid column name'
            ));

        $this->app->instance(AiSqlManager::class, $mockAi);

        $response = $this->postJson('/api/v1/query', [
            'question' => 'List product names',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('schema_validation.valid'));
        $this->assertEquals('SELECT name FROM products', $response->json('sql'));
    }

    /**
     * Test 11: Controlled SQL retry halts safely after 1 attempt if retry also fails.
     */
    public function test_controlled_sql_retry_halts_safely_if_retry_fails(): void
    {
        $mockAi = $this->createMock(AiSqlManager::class);

        // Initial query has invalid column
        $mockAi->expects($this->once())
            ->method('generateSql')
            ->willReturn(new AiSqlResponse(
                success: true,
                sql: 'SELECT invalid_col_1 FROM products',
                confidence: 0.70,
                explanation: 'Invalid initial query'
            ));

        // Retry also produces an invalid column
        $mockAi->expects($this->once())
            ->method('generateSqlWithCorrection')
            ->willReturn(new AiSqlResponse(
                success: true,
                sql: 'SELECT still_invalid_col_2 FROM products',
                confidence: 0.60,
                explanation: 'Still invalid after retry'
            ));

        $this->app->instance(AiSqlManager::class, $mockAi);

        $response = $this->postJson('/api/v1/query', [
            'question' => 'Query invalid columns',
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('schema_validation.valid'));
        $this->assertFalse($response->json('execution.success'));
        $this->assertStringContainsString('Unknown column reference', $response->json('schema_validation.reason'));
    }

    /**
     * Test 12: Dialect-aware date validation accepts MySQL and PostgreSQL date functions.
     */
    public function test_dialect_aware_date_validation_accepts_mysql_and_postgres_functions(): void
    {
        $validator = app(SqlSchemaValidator::class);

        // MySQL date queries
        $mysqlQueries = [
            "SELECT CURDATE() AS today, NOW() AS ts FROM customers",
            "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) FROM orders GROUP BY DATE_FORMAT(order_date, '%Y-%m')",
            "SELECT * FROM orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
            "SELECT DATEDIFF(NOW(), order_date) AS days_ago FROM orders",
        ];

        foreach ($mysqlQueries as $sql) {
            $result = $validator->validate($sql, null, 'mysql');
            $this->assertTrue($result['valid'], "MySQL query rejected: {$sql}. Reason: " . ($result['reason'] ?? 'none'));
        }

        // PostgreSQL date queries
        $pgsqlQueries = [
            "SELECT CURRENT_DATE AS today, CURRENT_TIMESTAMP AS ts FROM customers",
            "SELECT DATE_TRUNC('month', order_date) AS month, SUM(total_amount) FROM orders GROUP BY DATE_TRUNC('month', order_date)",
            "SELECT * FROM orders WHERE order_date >= CURRENT_DATE - INTERVAL '1 month'",
            "SELECT EXTRACT(YEAR FROM order_date) AS yr FROM orders",
        ];

        foreach ($pgsqlQueries as $sql) {
            $result = $validator->validate($sql, null, 'pgsql');
            $this->assertTrue($result['valid'], "PostgreSQL query rejected: {$sql}. Reason: " . ($result['reason'] ?? 'none'));
        }
    }

    /**
     * Test 13: Prompt context formatting redacts credentials and honors context caps.
     */
    public function test_prompt_context_redaction_and_size_safety(): void
    {
        $relevance = app(SchemaRelevanceService::class);
        $schemaService = app(DatabaseSchemaService::class);
        $schema = $schemaService->getSchemaDetails();

        $context = $relevance->formatPromptContext($schema, 'mysql');

        $this->assertStringNotContainsString('password', strtolower($context));
        $this->assertStringNotContainsString('127.0.0.1', $context);
        $this->assertStringNotContainsString('3306', $context);
        $this->assertStringContainsString('Database Dialect: MySQL', $context);
        $this->assertStringContainsString('- Table: customers', $context);
        $this->assertStringContainsString('Foreign Key Relationships:', $context);
    }
}
