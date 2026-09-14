<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Models\SavedQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedQueryTest extends TestCase
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

        // Setup Company A, User A, Connection A
        $this->companyA = Company::create(['name' => 'Acme Analytics']);
        $this->userA = User::create([
            'name' => 'Alice Analyst',
            'email' => 'alice@acme.com',
            'password' => 'Password123!',
            'company_id' => $this->companyA->id,
        ]);
        $this->tokenA = $this->userA->createToken('token-a')->plainTextToken;

        $this->connectionA = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Acme Primary MySQL',
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'text_to_sql_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'status' => 'connected',
        ]);

        // Setup Company B, User B, Connection B
        $this->companyB = Company::create(['name' => 'Beta Business']);
        $this->userB = User::create([
            'name' => 'Bob Beta',
            'email' => 'bob@beta.com',
            'password' => 'Password123!',
            'company_id' => $this->companyB->id,
        ]);
        $this->tokenB = $this->userB->createToken('token-b')->plainTextToken;

        $this->connectionB = DatabaseConnection::create([
            'company_id' => $this->companyB->id,
            'name' => 'Beta Reporting Store',
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
     * Test 1: Unauthenticated request to saved queries endpoint is rejected.
     */
    public function test_unauthenticated_request_to_saved_queries_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/saved-queries');
        $response->assertStatus(401);

        $createResponse = $this->postJson('/api/v1/saved-queries', [
            'name' => 'Monthly Revenue',
            'natural_language_question' => 'Total revenue by month',
            'sql' => 'SELECT id FROM orders',
        ]);
        $createResponse->assertStatus(401);
    }

    /**
     * Test 2: Authenticated user can create a saved query for their customer database.
     */
    public function test_authenticated_user_can_create_saved_query_for_customer_database(): void
    {
        $payload = [
            'name' => 'Top 10 Customers by Revenue',
            'description' => 'Identifies high-value customers for loyalty rewards',
            'natural_language_question' => 'Which customers generated the most revenue?',
            'sql' => 'SELECT customer_id, SUM(total_amount) AS revenue FROM orders GROUP BY customer_id ORDER BY revenue DESC LIMIT 10',
            'database_connection_id' => $this->connectionA->id,
            'dialect' => 'mysql',
            'result_visualization_type' => 'bar',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/saved-queries', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Top 10 Customers by Revenue',
                    'description' => 'Identifies high-value customers for loyalty rewards',
                    'company_id' => $this->companyA->id,
                    'database_connection_id' => $this->connectionA->id,
                    'target_database_name' => 'Acme Primary MySQL',
                    'is_demo' => false,
                    'dialect' => 'mysql',
                    'result_visualization_type' => 'bar',
                ]
            ]);

        $this->assertDatabaseHas('saved_queries', [
            'company_id' => $this->companyA->id,
            'name' => 'Top 10 Customers by Revenue',
            'database_connection_id' => $this->connectionA->id,
        ]);
    }

    /**
     * Test 3: Authenticated user can create a saved query for demo database when database_connection_id is omitted.
     */
    public function test_authenticated_user_can_create_saved_query_for_demo_database(): void
    {
        $payload = [
            'name' => 'Demo Order Count',
            'natural_language_question' => 'How many orders were placed?',
            'sql' => 'SELECT COUNT(*) AS total_orders FROM orders',
            'database_connection_id' => null,
            'result_visualization_type' => 'table',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/saved-queries', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Demo Order Count',
                    'is_demo' => true,
                    'target_database_name' => 'Demo Database (MySQL)',
                    'database_connection_id' => null,
                ]
            ]);
    }

    /**
     * Test 4: User cannot save a query with another company's database connection.
     */
    public function test_user_cannot_save_query_with_another_companys_database_connection(): void
    {
        $payload = [
            'name' => 'Sneaky Cross-Tenant Query',
            'natural_language_question' => 'Show orders',
            'sql' => 'SELECT * FROM orders',
            'database_connection_id' => $this->connectionB->id, // Belongs to Company B
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/saved-queries', $payload);

        $response->assertStatus(404)
            ->assertJsonFragment([
                'message' => 'Database connection not found or unauthorized.'
            ]);

        $this->assertDatabaseMissing('saved_queries', [
            'name' => 'Sneaky Cross-Tenant Query',
        ]);
    }

    /**
     * Test 5: Saved query creation enforces SQL guardrails against destructive DDL/DML.
     */
    public function test_saved_query_creation_enforces_sql_guardrails_against_destructive_statements(): void
    {
        $destructiveQueries = [
            'DROP TABLE customers',
            'DELETE FROM orders WHERE id = 1',
            'UPDATE products SET price = 0',
            'ALTER TABLE customers ADD COLUMN secret VARCHAR(255)',
            'SELECT * FROM orders; DROP TABLE orders;',
        ];

        foreach ($destructiveQueries as $unsafeSql) {
            $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
                ->postJson('/api/v1/saved-queries', [
                    'name' => 'Dangerous Query',
                    'natural_language_question' => 'Harm database',
                    'sql' => $unsafeSql,
                ]);

            $response->assertStatus(422)
                ->assertJsonStructure(['message', 'error']);
        }

        $this->assertDatabaseMissing('saved_queries', [
            'name' => 'Dangerous Query',
        ]);
    }

    /**
     * Test 6: Saved query listing enforces strict tenant isolation.
     */
    public function test_saved_query_listing_enforces_strict_tenant_isolation(): void
    {
        // Company A query
        SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'target_database_name' => $this->connectionA->name,
            'name' => 'Acme Internal Sales',
            'natural_language_question' => 'Total sales',
            'sql' => 'SELECT SUM(total_amount) FROM orders',
        ]);

        // Company B query
        SavedQuery::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'database_connection_id' => $this->connectionB->id,
            'target_database_name' => $this->connectionB->name,
            'name' => 'Beta Private Logistics',
            'natural_language_question' => 'Logistics inventory',
            'sql' => 'SELECT * FROM inventory',
        ]);

        // Alice (Company A) requests saved queries
        $responseA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/saved-queries');

        $responseA->assertStatus(200);
        $namesA = array_column($responseA->json('data'), 'name');
        $this->assertContains('Acme Internal Sales', $namesA);
        $this->assertNotContains('Beta Private Logistics', $namesA);

        app('auth')->forgetGuards();

        // Bob (Company B) requests saved queries
        $responseB = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->getJson('/api/v1/saved-queries');

        $responseB->assertStatus(200);
        $namesB = array_column($responseB->json('data'), 'name');
        $this->assertContains('Beta Private Logistics', $namesB);
        $this->assertNotContains('Acme Internal Sales', $namesB);
    }

    /**
     * Test 7: Search query filtering matches name, description, and natural language question.
     */
    public function test_saved_query_search_filters_by_name_description_and_question(): void
    {
        SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Monthly Recurring Revenue',
            'description' => 'Subscription metrics',
            'natural_language_question' => 'Calculate MRR for this quarter',
            'sql' => 'SELECT id FROM orders',
        ]);

        SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Customer Churn Analysis',
            'description' => 'Inactive accounts report',
            'natural_language_question' => 'Which clients did not reorder?',
            'sql' => 'SELECT id FROM customers',
        ]);

        // Search by name
        $searchName = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/saved-queries?search=Recurring');
        $this->assertCount(1, $searchName->json('data'));
        $this->assertEquals('Monthly Recurring Revenue', $searchName->json('data.0.name'));

        // Search by description
        $searchDesc = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/saved-queries?search=Inactive');
        $this->assertCount(1, $searchDesc->json('data'));
        $this->assertEquals('Customer Churn Analysis', $searchDesc->json('data.0.name'));

        // Search by question
        $searchQuestion = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/saved-queries?search=reorder');
        $this->assertCount(1, $searchQuestion->json('data'));
        $this->assertEquals('Customer Churn Analysis', $searchQuestion->json('data.0.name'));
    }

    /**
     * Test 8: Filter saved queries by database connection ID.
     */
    public function test_saved_query_filtering_by_database_connection(): void
    {
        SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'name' => 'Connected Query',
            'natural_language_question' => 'Query on customer db',
            'sql' => 'SELECT 1',
        ]);

        SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => null,
            'is_demo' => true,
            'name' => 'Demo Query',
            'natural_language_question' => 'Query on demo db',
            'sql' => 'SELECT 2',
        ]);

        $filteredConn = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/saved-queries?database_connection_id={$this->connectionA->id}");
        $this->assertCount(1, $filteredConn->json('data'));
        $this->assertEquals('Connected Query', $filteredConn->json('data.0.name'));

        $filteredDemo = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/saved-queries?database_connection_id=null");
        $this->assertCount(1, $filteredDemo->json('data'));
        $this->assertEquals('Demo Query', $filteredDemo->json('data.0.name'));
    }

    /**
     * Test 9: Updating a saved query enforces tenant isolation and guardrails.
     */
    public function test_updating_saved_query_enforces_tenant_isolation_and_guardrails(): void
    {
        $savedQueryA = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Original Name',
            'natural_language_question' => 'Question',
            'sql' => 'SELECT id FROM orders',
        ]);

        // User B cannot update User A's query
        $responseB = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->putJson("/api/v1/saved-queries/{$savedQueryA->id}", [
                'name' => 'Hacked by Company B',
            ]);
        $responseB->assertStatus(404);

        app('auth')->forgetGuards();

        // User A can update their query
        $responseA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->putJson("/api/v1/saved-queries/{$savedQueryA->id}", [
                'name' => 'Updated Name',
                'result_visualization_type' => 'line',
            ]);
        $responseA->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Name',
                    'result_visualization_type' => 'line',
                ]
            ]);

        // Updating with unsafe SQL is blocked
        $unsafeUpdate = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->putJson("/api/v1/saved-queries/{$savedQueryA->id}", [
                'sql' => 'DROP TABLE customers',
            ]);
        $unsafeUpdate->assertStatus(422);
    }

    /**
     * Test 10: Deleting a saved query enforces tenant isolation.
     */
    public function test_deleting_saved_query_enforces_tenant_isolation(): void
    {
        $savedQueryA = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'To Be Deleted',
            'natural_language_question' => 'Delete me',
            'sql' => 'SELECT 1',
        ]);

        // User B cannot delete Company A's query
        $deleteB = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->deleteJson("/api/v1/saved-queries/{$savedQueryA->id}");
        $deleteB->assertStatus(404);
        $this->assertDatabaseHas('saved_queries', ['id' => $savedQueryA->id]);

        app('auth')->forgetGuards();

        // User A can delete their query
        $deleteA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->deleteJson("/api/v1/saved-queries/{$savedQueryA->id}");
        $deleteA->assertStatus(200);
        $this->assertDatabaseMissing('saved_queries', ['id' => $savedQueryA->id]);
    }

    /**
     * Test 11: Executing a saved query runs through the security pipeline and logs source as saved_query.
     */
    public function test_executing_saved_query_runs_through_security_pipeline_and_logs_source(): void
    {
        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'target_database_name' => $this->connectionA->name,
            'name' => 'Total Orders Count',
            'natural_language_question' => 'How many orders exist?',
            'sql' => 'SELECT COUNT(*) AS total_orders FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/saved-queries/{$savedQuery->id}/execute");

        $response->assertStatus(200)
            ->assertJson([
                'question' => 'How many orders exist?',
                'sql' => 'SELECT COUNT(*) AS total_orders FROM orders',
                'guardrails' => ['allowed' => true],
                'schema_validation' => ['valid' => true],
                'execution' => ['success' => true],
                'visualization_type' => 'table',
                'saved_query_id' => $savedQuery->id,
            ]);

        // Verify that QueryLog recorded source = 'saved_query'
        $this->assertDatabaseHas('query_logs', [
            'company_id' => $this->companyA->id,
            'database_connection_id' => $this->connectionA->id,
            'source' => 'saved_query',
            'execution_status' => 'success',
        ]);
    }

    /**
     * Test 12: Executing saved query with deleted connection halts safely without falling back to demo.
     */
    public function test_executing_saved_query_with_deleted_database_connection_halts_safely_without_falling_back_to_demo(): void
    {
        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'target_database_name' => 'Acme Primary MySQL',
            'name' => 'Orphaned Saved Query',
            'natural_language_question' => 'Show revenue',
            'sql' => 'SELECT SUM(total_amount) FROM orders',
            'is_demo' => false,
        ]);

        // Now delete the database connection
        $this->connectionA->delete();

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/saved-queries/{$savedQuery->id}/execute");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => "This saved query's database connection is no longer available.",
                'error' => "This saved query's database connection is no longer available.",
            ]);
    }

    /**
     * Test 13: Executing a saved query enforces active schema validation against schema drift.
     */
    public function test_executing_saved_query_enforces_active_schema_validation(): void
    {
        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => null, // Demo mode
            'is_demo' => true,
            'name' => 'Schema Drift Query',
            'natural_language_question' => 'Query nonexistent column',
            'sql' => 'SELECT non_existent_column_xyz FROM orders',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/saved-queries/{$savedQuery->id}/execute");

        $response->assertStatus(200)
            ->assertJson([
                'schema_validation' => [
                    'valid' => false,
                ],
                'execution' => [
                    'success' => false,
                ]
            ]);

        $this->assertDatabaseHas('query_logs', [
            'company_id' => $this->companyA->id,
            'source' => 'saved_query',
            'execution_status' => 'failed',
        ]);
    }

    /**
     * Test 14: Saved queries and execution responses never leak database credentials.
     */
    public function test_saved_queries_and_execution_responses_never_leak_database_credentials(): void
    {
        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'target_database_name' => $this->connectionA->name,
            'name' => 'Credential Privacy Test',
            'natural_language_question' => 'Check secrets',
            'sql' => 'SELECT id FROM orders',
        ]);

        // Check index response
        $indexRes = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/saved-queries');
        $indexContent = $indexRes->getContent();
        $this->assertStringNotContainsString('password', $indexContent);

        // Check show response
        $showRes = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/saved-queries/{$savedQuery->id}");
        $showContent = $showRes->getContent();
        $this->assertStringNotContainsString('password', $showContent);

        // Check execute response
        $execRes = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/saved-queries/{$savedQuery->id}/execute");
        $execContent = $execRes->getContent();
        $this->assertStringNotContainsString('password', $execContent);
    }
}
