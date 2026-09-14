<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Models\SavedQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
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
     * Test 1: Unauthenticated request to dashboards endpoint is rejected.
     */
    public function test_unauthenticated_request_to_dashboards_is_rejected(): void
    {
        $this->getJson('/api/v1/dashboards')->assertStatus(401);
        $this->postJson('/api/v1/dashboards', ['name' => 'Sales'])->assertStatus(401);
        $this->getJson('/api/v1/dashboards/1')->assertStatus(401);
        $this->postJson('/api/v1/dashboards/1/widgets', ['saved_query_id' => 1])->assertStatus(401);
        $this->postJson('/api/v1/dashboards/1/execute')->assertStatus(401);
    }

    /**
     * Test 2: Authenticated user can create a dashboard for their company.
     */
    public function test_authenticated_user_can_create_dashboard(): void
    {
        $payload = [
            'name' => 'Executive Sales Overview',
            'description' => 'Key sales metrics for management',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/dashboards', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Executive Sales Overview',
                    'description' => 'Key sales metrics for management',
                    'company_id' => $this->companyA->id,
                    'user_id' => $this->userA->id,
                    'widgets_count' => 0,
                ],
            ]);

        $this->assertDatabaseHas('dashboards', [
            'company_id' => $this->companyA->id,
            'name' => 'Executive Sales Overview',
        ]);
    }

    /**
     * Test 3: Dashboard listing enforces strict tenant isolation.
     */
    public function test_dashboards_listing_enforces_strict_tenant_isolation(): void
    {
        // Create Dashboard A for Company A
        Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Acme Operations',
            'description' => 'Acme operational dashboard',
        ]);

        // Create Dashboard B for Company B
        Dashboard::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'name' => 'Beta Logistics',
            'description' => 'Beta logistics dashboard',
        ]);

        // Company A request
        $responseA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/dashboards');

        $responseA->assertStatus(200);
        $dashboardsA = $responseA->json('data');
        $this->assertCount(1, $dashboardsA);
        $this->assertEquals('Acme Operations', $dashboardsA[0]['name']);

        app('auth')->forgetGuards();

        // Company B request
        $responseB = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->getJson('/api/v1/dashboards');

        $responseB->assertStatus(200);
        $dashboardsB = $responseB->json('data');
        $this->assertCount(1, $dashboardsB);
        $this->assertEquals('Beta Logistics', $dashboardsB[0]['name']);
    }

    /**
     * Test 4: Dashboard search filters by name and description.
     */
    public function test_dashboard_search_filters_by_name_and_description(): void
    {
        Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Revenue Analytics',
            'description' => 'Financial metrics',
        ]);

        Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Customer Retention',
            'description' => 'Churn and cohort analysis',
        ]);

        $searchResponse = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson('/api/v1/dashboards?search=Churn');

        $searchResponse->assertStatus(200);
        $results = $searchResponse->json('data');
        $this->assertCount(1, $results);
        $this->assertEquals('Customer Retention', $results[0]['name']);
    }

    /**
     * Test 5: Single dashboard retrieval enforces tenant isolation.
     */
    public function test_dashboard_retrieval_enforces_tenant_isolation(): void
    {
        $dashboardA = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Acme KPI Dashboard',
        ]);

        // User A can access
        $resA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/dashboards/{$dashboardA->id}");
        $resA->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $dashboardA->id,
                    'name' => 'Acme KPI Dashboard',
                ],
            ]);

        app('auth')->forgetGuards();

        // User B cannot access (cross-tenant 404)
        $resB = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->getJson("/api/v1/dashboards/{$dashboardA->id}");
        $resB->assertStatus(404);
    }

    /**
     * Test 6: Updating a dashboard enforces tenant isolation.
     */
    public function test_updating_dashboard_enforces_tenant_isolation(): void
    {
        $dashboardA = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Initial Title',
            'description' => 'Initial desc',
        ]);

        // User B attempt fails with 404
        $resB = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->patchJson("/api/v1/dashboards/{$dashboardA->id}", [
                'name' => 'Hacked by Beta',
            ]);
        $resB->assertStatus(404);

        app('auth')->forgetGuards();

        // User A succeeds
        $resA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->patchJson("/api/v1/dashboards/{$dashboardA->id}", [
                'name' => 'Updated Title',
            ]);
        $resA->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Title',
                ],
            ]);

        $this->assertDatabaseHas('dashboards', [
            'id' => $dashboardA->id,
            'name' => 'Updated Title',
        ]);
    }

    /**
     * Test 7: Deleting a dashboard enforces tenant isolation.
     */
    public function test_deleting_dashboard_enforces_tenant_isolation(): void
    {
        $dashboardA = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Dashboard To Delete',
        ]);

        // User B cannot delete Dashboard A
        $resB = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->deleteJson("/api/v1/dashboards/{$dashboardA->id}");
        $resB->assertStatus(404);

        app('auth')->forgetGuards();

        // User A deletes successfully
        $resA = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->deleteJson("/api/v1/dashboards/{$dashboardA->id}");
        $resA->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('dashboards', ['id' => $dashboardA->id]);
    }

    /**
     * Test 8: Authenticated user can add a saved query widget to a dashboard.
     */
    public function test_authenticated_user_can_add_saved_query_widget_to_dashboard(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Sales Overview',
        ]);

        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'target_database_name' => $this->connectionA->name,
            'is_demo' => false,
            'name' => 'Monthly Revenue',
            'natural_language_question' => 'Monthly revenue',
            'sql' => 'SELECT count(*) as total_orders FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'metric',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/widgets", [
                'saved_query_id' => $savedQuery->id,
                'title' => 'Custom Revenue Title',
                'visualization_type' => 'metric',
                'width' => 1,
                'height' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'dashboard_id' => $dashboard->id,
                    'saved_query_id' => $savedQuery->id,
                    'title' => 'Custom Revenue Title',
                    'visualization_type' => 'metric',
                    'position' => 0,
                    'width' => 1,
                ],
            ]);

        $this->assertDatabaseHas('dashboard_widgets', [
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $savedQuery->id,
            'title' => 'Custom Revenue Title',
        ]);
    }

    /**
     * Test 9: User CANNOT add another company's saved query as a widget.
     */
    public function test_user_cannot_add_another_companys_saved_query_as_widget(): void
    {
        $dashboardA = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Acme Overview',
        ]);

        // Saved Query belongs to Company B!
        $savedQueryB = SavedQuery::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'database_connection_id' => $this->connectionB->id,
            'target_database_name' => $this->connectionB->name,
            'is_demo' => false,
            'name' => 'Beta Confidential Queries',
            'natural_language_question' => 'Secret data',
            'sql' => 'SELECT count(*) FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        // User A tries to add Company B's saved query to Company A's dashboard
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboardA->id}/widgets", [
                'saved_query_id' => $savedQueryB->id,
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('dashboard_widgets', [
            'dashboard_id' => $dashboardA->id,
            'saved_query_id' => $savedQueryB->id,
        ]);
    }

    /**
     * Test 10: Updating and deleting a widget enforces tenant isolation.
     */
    public function test_updating_and_deleting_widget_enforces_tenant_isolation(): void
    {
        $dashboardA = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Acme Overview',
        ]);

        $savedQueryA = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'name' => 'Order Counts',
            'natural_language_question' => 'Order count',
            'sql' => 'SELECT count(*) as cnt FROM orders',
        ]);

        $widget = DashboardWidget::create([
            'dashboard_id' => $dashboardA->id,
            'saved_query_id' => $savedQueryA->id,
            'title' => 'Original Title',
            'visualization_type' => 'bar',
            'position' => 0,
            'width' => 1,
        ]);

        // User B cannot update
        $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->patchJson("/api/v1/dashboards/{$dashboardA->id}/widgets/{$widget->id}", [
                'title' => 'Compromised',
            ])
            ->assertStatus(404);

        app('auth')->forgetGuards();

        // User A can update
        $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->patchJson("/api/v1/dashboards/{$dashboardA->id}/widgets/{$widget->id}", [
                'title' => 'Updated Widget Title',
                'visualization_type' => 'line',
                'width' => 2,
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'title' => 'Updated Widget Title',
                    'visualization_type' => 'line',
                    'width' => 2,
                ],
            ]);

        app('auth')->forgetGuards();

        // User B cannot delete
        $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->deleteJson("/api/v1/dashboards/{$dashboardA->id}/widgets/{$widget->id}")
            ->assertStatus(404);

        app('auth')->forgetGuards();

        // User A can delete
        $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->deleteJson("/api/v1/dashboards/{$dashboardA->id}/widgets/{$widget->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('dashboard_widgets', ['id' => $widget->id]);
    }

    /**
     * Test 11: Dashboard execution runs all widgets through security pipeline and logs source = 'dashboard'.
     */
    public function test_dashboard_execution_runs_all_widgets_through_security_pipeline(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Live Analytics',
        ]);

        // Widget 1: Demo DB Query
        $savedQuery1 = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => null,
            'is_demo' => true,
            'target_database_name' => 'Demo Database (MySQL)',
            'name' => 'Total Customers',
            'natural_language_question' => 'How many customers do we have?',
            'sql' => 'SELECT count(*) as total_customers FROM customers',
            'dialect' => 'mysql',
            'result_visualization_type' => 'metric',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $savedQuery1->id,
            'title' => 'Customers Metric',
            'visualization_type' => 'metric',
            'position' => 0,
        ]);

        // Execute Dashboard
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'dashboard_id' => $dashboard->id,
                'dashboard_name' => 'Live Analytics',
            ]);

        $widgets = $response->json('widgets');
        $this->assertCount(1, $widgets);
        $this->assertTrue($widgets[0]['success']);
        $this->assertEquals('Customers Metric', $widgets[0]['title']);
        $this->assertNotEmpty($widgets[0]['results']);

        // Assert query log created with source = 'dashboard'
        $this->assertDatabaseHas('query_logs', [
            'company_id' => $this->companyA->id,
            'source' => 'dashboard',
            'execution_status' => 'success',
        ]);
    }

    /**
     * Test 12: Partial failure handling — one widget with unavailable connection does not break other widgets.
     */
    public function test_partial_widget_failure_handling_with_unavailable_connection(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Resilience Test Dashboard',
        ]);

        // Widget 1: Healthy Demo query
        $healthyQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Healthy Widget',
            'natural_language_question' => 'Healthy query',
            'sql' => 'SELECT count(*) as count FROM customers',
            'dialect' => 'mysql',
            'result_visualization_type' => 'metric',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $healthyQuery->id,
            'title' => 'Healthy Widget',
            'position' => 0,
        ]);

        // Widget 2: Query referencing a database connection that gets deleted
        $tempConn = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Ephemeral DB',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'root',
            'password' => 'secret',
            'status' => 'connected',
        ]);

        $brokenQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $tempConn->id,
            'is_demo' => false,
            'name' => 'Broken Connection Widget',
            'natural_language_question' => 'Broken query',
            'sql' => 'SELECT count(*) as count FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $brokenQuery->id,
            'title' => 'Broken Widget',
            'position' => 1,
        ]);

        // Delete the database connection to simulate connection loss
        $tempConn->delete();

        // Execute Dashboard
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute");

        // Whole dashboard MUST return 200
        $response->assertStatus(200);

        $widgets = $response->json('widgets');
        $this->assertCount(2, $widgets);

        // Widget 1 should be healthy
        $this->assertTrue($widgets[0]['success']);
        $this->assertNull($widgets[0]['error']);
        $this->assertNotEmpty($widgets[0]['results']);

        // Widget 2 should safely indicate failure without crashing the dashboard
        $this->assertFalse($widgets[1]['success']);
        $this->assertStringContainsString('no longer available', $widgets[1]['error']);
        $this->assertEmpty($widgets[1]['results']);
    }

    /**
     * Test 13: Schema drift on a widget query fails safely with friendly error message.
     */
    public function test_schema_drift_on_widget_query_fails_safely(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Schema Drift Dashboard',
        ]);

        // Query referencing dropped or nonexistent column
        $driftedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'is_demo' => true,
            'name' => 'Drifted Query',
            'natural_language_question' => 'Select dropped column',
            'sql' => 'SELECT non_existent_column_xyz FROM customers',
            'dialect' => 'mysql',
            'result_visualization_type' => 'table',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $driftedQuery->id,
            'title' => 'Drifted Column Widget',
            'position' => 0,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute");

        $response->assertStatus(200);
        $widgets = $response->json('widgets');
        $this->assertCount(1, $widgets);
        $this->assertFalse($widgets[0]['success']);
        $this->assertStringContainsString('Schema validation failed', $widgets[0]['error']);
    }

    /**
     * Test 14: Cross-tenant dashboard execution is rejected with 404.
     */
    public function test_cross_tenant_dashboard_execution_is_rejected(): void
    {
        $dashboardA = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Company A Secret Dashboard',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->postJson("/api/v1/dashboards/{$dashboardA->id}/execute");

        $response->assertStatus(404);
    }

    /**
     * Test 15: Dashboard responses and widget execution results never leak credentials.
     */
    public function test_dashboard_responses_never_leak_credentials(): void
    {
        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Security Audit Dashboard',
        ]);

        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'database_connection_id' => $this->connectionA->id,
            'target_database_name' => $this->connectionA->name,
            'is_demo' => false,
            'name' => 'Sensitive Query',
            'natural_language_question' => 'Sensitive data',
            'sql' => 'SELECT count(*) as total FROM orders',
            'dialect' => 'mysql',
            'result_visualization_type' => 'metric',
        ]);

        DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $savedQuery->id,
            'position' => 0,
        ]);

        // 1. Show dashboard response
        $showRes = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/dashboards/{$dashboard->id}");
        $showJson = $showRes->getContent();
        $this->assertStringNotContainsString('password', strtolower($showJson));
        $this->assertStringNotContainsString('Password123!', $showJson);

        // 2. Execute dashboard response
        $execRes = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson("/api/v1/dashboards/{$dashboard->id}/execute");
        $execJson = $execRes->getContent();
        $this->assertStringNotContainsString('password', strtolower($execJson));
        $this->assertStringNotContainsString('Password123!', $execJson);
    }
}
