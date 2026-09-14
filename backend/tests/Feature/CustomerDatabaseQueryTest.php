<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Models\User;
use App\Services\AiSqlManager;
use App\Services\Ai\AiSqlResponse;
use App\Services\SqlExecutorService;
use App\Services\SqlSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerDatabaseQueryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected User $userA;
    protected string $tokenA;
    protected DatabaseConnection $connectionA;

    protected Company $companyB;
    protected User $userB;
    protected string $tokenB;
    protected DatabaseConnection $connectionB;

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

        // Register valid MySQL connection for Company A using test environment DB credentials
        $this->connectionA = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Acme Primary MySQL',
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'text_to_sql'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'status' => 'connected',
        ]);

        // Setup Company B and User B
        $this->companyB = Company::create(['name' => 'Beta Logistics']);
        $this->userB = User::create([
            'name' => 'Bob Beta',
            'email' => 'bob@beta.com',
            'password' => 'Password123!',
            'company_id' => $this->companyB->id,
        ]);
        $this->tokenB = $this->userB->createToken('test-token-b')->plainTextToken;

        $this->connectionB = DatabaseConnection::create([
            'company_id' => $this->companyB->id,
            'name' => 'Beta MySQL Store',
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'text_to_sql'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'status' => 'connected',
        ]);
    }

    /**
     * Test 1: Unauthenticated requests specifying database_connection_id are rejected with 401.
     */
    public function test_unauthenticated_request_with_database_connection_id_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/query', [
            'question' => 'How many customers do we have?',
            'database_connection_id' => $this->connectionA->id,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated. Please log in to query a customer database.'
        ]);
    }

    /**
     * Test 2: Tenant isolation prevents querying database connections belonging to another company.
     */
    public function test_cannot_query_database_connection_of_another_company(): void
    {
        // User A attempts to use Company B's database connection
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/query', [
                'question' => 'How many customers do we have?',
                'database_connection_id' => $this->connectionB->id,
            ]);

        $response->assertStatus(404);
        $response->assertJson([
            'message' => 'Database connection not found or access denied.'
        ]);
    }

    /**
     * Test 3: Authenticated user executes query against their customer database connection with schema validation and audit logging.
     */
    public function test_customer_database_query_execution_and_schema_validation(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/query', [
                'question' => 'How many customers do we have?',
                'database_connection_id' => $this->connectionA->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'question',
            'sql',
            'guardrails' => ['allowed'],
            'schema_validation' => ['valid'],
            'execution' => ['success', 'results', 'time_ms'],
        ]);

        $this->assertTrue($response->json('guardrails.allowed'));
        $this->assertTrue($response->json('schema_validation.valid'));
        $this->assertTrue($response->json('execution.success'));

        // Verify QueryLog was created with tenant context
        $log = QueryLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->userA->id, $log->user_id);
        $this->assertEquals($this->companyA->id, $log->company_id);
        $this->assertEquals($this->connectionA->id, $log->database_connection_id);
        $this->assertEquals('success', $log->execution_status);
        $this->assertTrue($log->passed_guardrails);
    }

    /**
     * Test 4: Custom SQL execution against customer database connection.
     */
    public function test_custom_sql_execution_against_customer_database(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/query', [
                'question' => 'Count total customers',
                'sql' => 'SELECT COUNT(*) AS total FROM customers',
                'database_connection_id' => $this->connectionA->id,
            ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('execution.success'));
        $this->assertNotEmpty($response->json('execution.results'));

        $log = QueryLog::latest('id')->first();
        $this->assertEquals($this->userA->id, $log->user_id);
        $this->assertEquals($this->companyA->id, $log->company_id);
        $this->assertEquals($this->connectionA->id, $log->database_connection_id);
    }

    /**
     * Test 5: Guardrails reject destructive non-SELECT queries on customer database.
     */
    public function test_customer_database_query_rejects_non_select_statements(): void
    {
        $destructiveQueries = [
            'DROP TABLE customers',
            'DELETE FROM customers WHERE id = 1',
            'UPDATE customers SET name = "Hacked"',
            'ALTER TABLE customers ADD COLUMN evil VARCHAR(255)',
        ];

        foreach ($destructiveQueries as $sql) {
            $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
                ->postJson('/api/v1/query', [
                    'question' => 'Dangerous action',
                    'sql' => $sql,
                    'database_connection_id' => $this->connectionA->id,
                ]);

            $response->assertStatus(200);
            $this->assertFalse($response->json('guardrails.allowed'));
            $this->assertFalse($response->json('execution.success'));

            $log = QueryLog::latest('id')->first();
            $this->assertEquals('blocked', $log->execution_status);
            $this->assertFalse($log->passed_guardrails);
        }
    }

    /**
     * Test 6: Defense-in-depth in SqlExecutorService rejects queries not starting with SELECT or WITH.
     */
    public function test_defense_in_depth_executor_rejects_non_select_directly(): void
    {
        $executor = app(SqlExecutorService::class);
        $result = $executor->execute('INSERT INTO customers (name) VALUES ("Evil")');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Only read-only SELECT queries are permitted', $result['error']);
    }

    /**
     * Test 7: Step 17 Fix - SchemaValidator accepts standard SQL keywords and date/time functions.
     */
    public function test_schema_validator_accepts_sql_functions_and_keywords(): void
    {
        $validator = app(SqlSchemaValidator::class);

        $validSqlStatements = [
            "SELECT CURRENT_DATE AS today FROM customers",
            "SELECT NOW() AS current_ts FROM customers",
            "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) FROM orders GROUP BY month",
            "SELECT COALESCE(email, 'N/A') AS contact_email FROM customers",
            "SELECT ROUND(AVG(total_amount), 2) AS rounded_avg FROM orders",
            "SELECT CASE WHEN total_amount > 100 THEN 'high' ELSE 'low' END AS tier FROM orders",
        ];

        foreach ($validSqlStatements as $sql) {
            $result = $validator->validate($sql);
            $this->assertTrue(
                $result['valid'],
                "Expected SQL to pass schema validation: {$sql}. Reason: " . ($result['reason'] ?? 'none')
            );
        }
    }

    /**
     * Test 8: Backward compatibility - Demo database query runs without authentication and logs null tenant fields.
     */
    public function test_backward_compatible_demo_mode_when_no_connection_id_provided(): void
    {
        $response = $this->postJson('/api/v1/query', [
            'question' => 'How many customers do we have?',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('guardrails.allowed'));
        $this->assertTrue($response->json('execution.success'));

        $log = QueryLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
        $this->assertNull($log->company_id);
        $this->assertNull($log->database_connection_id);
    }

    /**
     * Test 9: History endpoint filters by tenant company for authenticated users.
     */
    public function test_history_filters_by_tenant_company_for_authenticated_users(): void
    {
        // 1. Create a query log for Company A
        QueryLog::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'database_connection_id' => $this->connectionA->id,
            'question' => 'Company A Question',
            'generated_sql' => 'SELECT 1',
            'passed_guardrails' => true,
            'execution_status' => 'success',
            'confidence_score' => 0.95,
        ]);

        // 2. Create a query log for Company B
        QueryLog::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'database_connection_id' => $this->connectionB->id,
            'question' => 'Company B Secret Question',
            'generated_sql' => 'SELECT 2',
            'passed_guardrails' => true,
            'execution_status' => 'success',
            'confidence_score' => 0.95,
        ]);

        // 3. Create an unauthenticated demo query log
        QueryLog::create([
            'user_id' => null,
            'company_id' => null,
            'database_connection_id' => null,
            'question' => 'Public Demo Question',
            'generated_sql' => 'SELECT 3',
            'passed_guardrails' => true,
            'execution_status' => 'success',
            'confidence_score' => 0.90,
        ]);

        // Request history as User A
        $responseA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/history');

        $responseA->assertStatus(200);
        $questionsA = collect($responseA->json('data'))->pluck('question')->all();

        $this->assertContains('Company A Question', $questionsA);
        $this->assertContains('Public Demo Question', $questionsA);
        $this->assertNotContains('Company B Secret Question', $questionsA);
    }

    /**
     * Test 10: Database credentials are never leaked in AI prompts or responses.
     */
    public function test_credentials_are_never_leaked_in_ai_prompts_or_responses(): void
    {
        $secretPassword = 'SuperSecretDbPasswordXYZ987!';
        $secureConn = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Secret Protected DB',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'fake_secret_db',
            'username' => 'fake_user',
            'password' => $secretPassword,
            'status' => 'connected',
        ]);

        // Mock Introspection Service to return simulated schema without opening live socket
        $mockIntrospection = $this->createMock(\App\Services\SchemaIntrospectionService::class);
        $mockIntrospection->expects($this->once())
            ->method('introspect')
            ->willReturn([
                'tables' => [
                    [
                        'name' => 'customers',
                        'columns' => [
                            ['name' => 'id', 'type' => 'int (PK)'],
                            ['name' => 'name', 'type' => 'varchar'],
                        ]
                    ]
                ],
                'relationships' => []
            ]);
        $mockIntrospection->expects($this->once())
            ->method('getTablesAndColumns')
            ->willReturn(['customers' => ['id', 'name']]);
        $mockIntrospection->expects($this->once())
            ->method('getPromptContext')
            ->willReturn("- Table: customers\n  Columns: id (int (PK)), name (varchar)");

        $this->app->instance(\App\Services\SchemaIntrospectionService::class, $mockIntrospection);

        // Mock AI manager to inspect what prompt / context it receives
        $mockAiManager = $this->createMock(AiSqlManager::class);
        $mockAiManager->expects($this->once())
            ->method('generateSql')
            ->with(
                $this->anything(),
                $this->callback(function (?string $schemaContext) use ($secretPassword) {
                    if ($schemaContext !== null) {
                        $this->assertStringNotContainsString($secretPassword, $schemaContext);
                    }
                    return true;
                }),
                $this->equalTo('mysql')
            )
            ->willReturn(new AiSqlResponse(
                success: true,
                sql: 'SELECT COUNT(*) AS total FROM customers',
                confidence: 0.99,
                explanation: 'Counts customers'
            ));

        $this->app->instance(AiSqlManager::class, $mockAiManager);

        // Mock Connection Manager and Executor so no runtime socket is opened with fake credentials
        $mockConnManager = $this->createMock(\App\Services\DatabaseConnectionManager::class);
        $mockConnManager->method('getConnectionName')
            ->willReturn('tenant_mock_conn');
        $this->app->instance(\App\Services\DatabaseConnectionManager::class, $mockConnManager);

        $mockExecutor = $this->createMock(SqlExecutorService::class);
        $mockExecutor->expects($this->once())
            ->method('execute')
            ->willReturn([
                'success' => true,
                'results' => [['total' => 5]],
                'time_ms' => 1.2,
                'error' => null,
            ]);
        $this->app->instance(SqlExecutorService::class, $mockExecutor);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/query', [
                'question' => 'Count customers safely',
                'database_connection_id' => $secureConn->id,
            ]);

        $response->assertStatus(200);
        $rawResponse = $response->getContent();
        $this->assertStringNotContainsString($secretPassword, $rawResponse);
    }
}


