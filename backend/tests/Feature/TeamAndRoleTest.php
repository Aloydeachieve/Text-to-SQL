<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DatabaseConnection;
use App\Models\SavedQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAndRoleTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected User $adminA;
    protected User $analystA;
    protected User $viewerA;
    protected User $adminB;
    protected string $adminAToken;
    protected string $analystAToken;
    protected string $viewerAToken;
    protected string $adminBToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'ABC Electronics']);
        $this->companyB = Company::create(['name' => 'XYZ Logistics']);

        $this->adminA = User::create([
            'name' => 'Admin Alice',
            'email' => 'admin.a@abcelectronics.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        $this->adminAToken = $this->adminA->createToken('test')->plainTextToken;

        $this->analystA = User::create([
            'name' => 'Analyst Bob',
            'email' => 'analyst.a@abcelectronics.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'analyst',
        ]);
        $this->analystAToken = $this->analystA->createToken('test')->plainTextToken;

        $this->viewerA = User::create([
            'name' => 'Viewer Charlie',
            'email' => 'viewer.a@abcelectronics.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'viewer',
        ]);
        $this->viewerAToken = $this->viewerA->createToken('test')->plainTextToken;

        $this->adminB = User::create([
            'name' => 'Admin Dave',
            'email' => 'admin.b@xyzlogistics.com',
            'password' => 'password123',
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);
        $this->adminBToken = $this->adminB->createToken('test')->plainTextToken;
    }

    protected function withAuth(string $token): static
    {
        if (app()->has('auth')) {
            app('auth')->forgetGuards();
        }
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_registration_assigns_admin_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Company Founder',
            'email' => 'founder@newcorp.com',
            'password' => 'SecurePass123!',
            'company_name' => 'NewCorp Global',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'user' => [
                    'name' => 'Company Founder',
                    'email' => 'founder@newcorp.com',
                    'role' => 'admin',
                    'company_name' => 'NewCorp Global',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'founder@newcorp.com',
            'role' => 'admin',
        ]);
    }

    public function test_login_and_me_return_role_and_company(): void
    {
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'analyst.a@abcelectronics.com',
            'password' => 'password123',
        ]);

        $loginRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => [
                    'email' => 'analyst.a@abcelectronics.com',
                    'role' => 'analyst',
                    'company_id' => $this->companyA->id,
                    'company_name' => 'ABC Electronics',
                ],
            ]);

        $meRes = $this->withAuth($this->analystAToken)
            ->getJson('/api/v1/auth/me');

        $meRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => [
                    'email' => 'analyst.a@abcelectronics.com',
                    'role' => 'analyst',
                    'company_id' => $this->companyA->id,
                    'company_name' => 'ABC Electronics',
                ],
            ]);

        // Asserts never leaks sensitive fields
        $this->assertArrayNotHasKey('password', $meRes->json('user'));
        $this->assertArrayNotHasKey('remember_token', $meRes->json('user'));
    }

    public function test_admin_can_create_update_and_remove_member(): void
    {
        // 1. Admin creates member
        $createRes = $this->withAuth($this->adminAToken)
            ->postJson('/api/v1/company/members', [
                'name' => 'New Analyst Dana',
                'email' => 'dana@abcelectronics.com',
                'role' => 'analyst',
                'password' => 'StrongPass123!',
            ]);

        $createRes->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'New Analyst Dana',
                    'email' => 'dana@abcelectronics.com',
                    'role' => 'analyst',
                    'company_id' => $this->companyA->id,
                ],
            ]);

        $newMemberId = $createRes->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->companyA->id,
            'action' => 'member_created',
        ]);

        // 2. Admin updates member role
        $updateRes = $this->withAuth($this->adminAToken)
            ->patchJson("/api/v1/company/members/{$newMemberId}", [
                'role' => 'viewer',
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $newMemberId,
                    'role' => 'viewer',
                ],
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->companyA->id,
            'action' => 'member_role_changed',
        ]);

        // 3. Admin removes member
        $deleteRes = $this->withAuth($this->adminAToken)
            ->deleteJson("/api/v1/company/members/{$newMemberId}");

        $deleteRes->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('users', ['id' => $newMemberId]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->companyA->id,
            'action' => 'member_removed',
        ]);
    }

    public function test_analyst_and_viewer_cannot_manage_members(): void
    {
        // Analyst attempts to create member -> 403
        $this->withAuth($this->analystAToken)
            ->postJson('/api/v1/company/members', [
                'name' => 'Hacker',
                'email' => 'hacker@abcelectronics.com',
                'role' => 'admin',
            ])
            ->assertStatus(403);

        // Viewer attempts to update member -> 403
        $this->withAuth($this->viewerAToken)
            ->patchJson("/api/v1/company/members/{$this->analystA->id}", [
                'role' => 'admin',
            ])
            ->assertStatus(403);

        // Viewer attempts to remove member -> 403
        $this->withAuth($this->viewerAToken)
            ->deleteJson("/api/v1/company/members/{$this->analystA->id}")
            ->assertStatus(403);
    }

    public function test_role_change_safety_invariants(): void
    {
        // Admin cannot change own role
        $selfRoleRes = $this->withAuth($this->adminAToken)
            ->patchJson("/api/v1/company/members/{$this->adminA->id}", [
                'role' => 'viewer',
            ]);

        $selfRoleRes->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You cannot modify your own role.',
            ]);

        // Cannot demote the last admin
        // Create second admin first to test demoting works when 2 admins exist
        $admin2 = User::create([
            'name' => 'Admin Second',
            'email' => 'admin2@abcelectronics.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $demoteRes = $this->withAuth($this->adminAToken)
            ->patchJson("/api/v1/company/members/{$admin2->id}", [
                'role' => 'analyst',
            ]);
        $demoteRes->assertStatus(200);

        // Now admin2 is analyst, so adminA is the only admin left.
        $secondDemote = $this->withAuth($this->adminAToken)
            ->patchJson("/api/v1/company/members/{$this->adminA->id}", [
                'role' => 'analyst',
            ]);
        $secondDemote->assertStatus(422);

        // Cannot delete the last admin
        $deleteLastAdminRes = $this->withAuth($this->adminAToken)
            ->deleteJson("/api/v1/company/members/{$this->adminA->id}");
        $deleteLastAdminRes->assertStatus(422);
    }

    public function test_member_deletion_preserves_company_resources(): void
    {
        // Create an analyst with a saved query and dashboard
        $analystToDel = User::create([
            'name' => 'Departing Analyst',
            'email' => 'departing@abcelectronics.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'analyst',
        ]);

        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $analystToDel->id,
            'name' => 'Departing Analyst Query',
            'natural_language_question' => 'Show customers',
            'sql' => 'SELECT * FROM customers',
            'visibility' => 'company',
            'is_demo' => true,
        ]);

        $dashboard = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $analystToDel->id,
            'name' => 'Departing Analyst Dashboard',
            'visibility' => 'company',
        ]);

        // Delete the analyst
        $delRes = $this->withAuth($this->adminAToken)
            ->deleteJson("/api/v1/company/members/{$analystToDel->id}");
        $delRes->assertStatus(200);

        // Query and dashboard still exist, user_id is nullified
        $this->assertDatabaseHas('saved_queries', [
            'id' => $savedQuery->id,
            'user_id' => null,
            'company_id' => $this->companyA->id,
        ]);
        $this->assertDatabaseHas('dashboards', [
            'id' => $dashboard->id,
            'user_id' => null,
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_cross_company_member_manipulation_is_rejected(): void
    {
        // Admin A attempts to modify or delete Admin B -> 404
        $this->withAuth($this->adminAToken)
            ->patchJson("/api/v1/company/members/{$this->adminB->id}", [
                'role' => 'viewer',
            ])
            ->assertStatus(404);

        $this->withAuth($this->adminAToken)
            ->deleteJson("/api/v1/company/members/{$this->adminB->id}")
            ->assertStatus(404);
    }

    public function test_saved_query_visibility_and_role_authorization(): void
    {
        // 1. Analyst A creates private query
        $privateQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->analystA->id,
            'name' => 'Bob Private Analysis',
            'natural_language_question' => 'Private question',
            'sql' => 'SELECT 1',
            'visibility' => 'private',
            'is_demo' => true,
        ]);

        // 2. Company-shared query created by Admin A
        $sharedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminA->id,
            'name' => 'Company Shared Analysis',
            'natural_language_question' => 'Shared question',
            'sql' => 'SELECT 2',
            'visibility' => 'company',
            'is_demo' => true,
        ]);

        // 3. Another analyst in Company A
        $analyst2 = User::create([
            'name' => 'Analyst Eva',
            'email' => 'eva@abcelectronics.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'analyst',
        ]);
        $evaToken = $analyst2->createToken('test')->plainTextToken;

        // Eva CANNOT access Bob's private query (403)
        $this->withAuth($evaToken)
            ->getJson("/api/v1/saved-queries/{$privateQuery->id}")
            ->assertStatus(403);

        // Eva CAN access company-shared query (200)
        $this->withAuth($evaToken)
            ->getJson("/api/v1/saved-queries/{$sharedQuery->id}")
            ->assertStatus(200);

        // Viewer A CAN access company-shared query (200)
        $this->withAuth($this->viewerAToken)
            ->getJson("/api/v1/saved-queries/{$sharedQuery->id}")
            ->assertStatus(200);

        // Viewer A CANNOT access Bob's private query (403)
        $this->withAuth($this->viewerAToken)
            ->getJson("/api/v1/saved-queries/{$privateQuery->id}")
            ->assertStatus(403);

        // Viewer A CANNOT edit company-shared query (403)
        $this->withAuth($this->viewerAToken)
            ->patchJson("/api/v1/saved-queries/{$sharedQuery->id}", [
                'name' => 'Vandalized Name',
            ])
            ->assertStatus(403);

        // Viewer A CANNOT delete company-shared query (403)
        $this->withAuth($this->viewerAToken)
            ->deleteJson("/api/v1/saved-queries/{$sharedQuery->id}")
            ->assertStatus(403);

        // Viewer A CANNOT create saved queries (403)
        $this->withAuth($this->viewerAToken)
            ->postJson('/api/v1/saved-queries', [
                'name' => 'Viewer Query',
                'natural_language_question' => 'Q',
                'sql' => 'SELECT 1',
            ])
            ->assertStatus(403);

        // Viewer CAN execute company-shared query
        $this->withAuth($this->viewerAToken)
            ->postJson("/api/v1/saved-queries/{$sharedQuery->id}/execute")
            ->assertStatus(200);

        // Viewer CANNOT execute private query (403)
        $this->withAuth($this->viewerAToken)
            ->postJson("/api/v1/saved-queries/{$privateQuery->id}/execute")
            ->assertStatus(403);

        // User from Company B cannot access Company A query (404)
        $this->withAuth($this->adminBToken)
            ->getJson("/api/v1/saved-queries/{$sharedQuery->id}")
            ->assertStatus(404);
    }

    public function test_dashboard_visibility_and_widget_authorization(): void
    {
        // 1. Admin A creates shared query
        $sharedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminA->id,
            'name' => 'Revenue Metric',
            'natural_language_question' => 'Revenue',
            'sql' => 'SELECT 100 as rev',
            'visibility' => 'company',
            'is_demo' => true,
        ]);

        // 2. Analyst A creates private query
        $privateQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->analystA->id,
            'name' => 'Confidential Metric',
            'natural_language_question' => 'Confidential',
            'sql' => 'SELECT 50 as secret',
            'visibility' => 'private',
            'is_demo' => true,
        ]);

        // 3. Admin A creates company-shared dashboard
        $sharedDash = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminA->id,
            'name' => 'Company Executive Dashboard',
            'visibility' => 'company',
        ]);

        // Add shared query to dashboard
        $this->withAuth($this->adminAToken)
            ->postJson("/api/v1/dashboards/{$sharedDash->id}/widgets", [
                'saved_query_id' => $sharedQuery->id,
                'title' => 'Revenue Widget',
            ])
            ->assertStatus(201);

        // Viewer CAN view shared dashboard
        $this->withAuth($this->viewerAToken)
            ->getJson("/api/v1/dashboards/{$sharedDash->id}")
            ->assertStatus(200);

        // Viewer CAN execute shared dashboard
        $execRes = $this->withAuth($this->viewerAToken)
            ->postJson("/api/v1/dashboards/{$sharedDash->id}/execute");
        $execRes->assertStatus(200)
            ->assertJson(['success' => true]);

        // Viewer CANNOT add widgets (403)
        $this->withAuth($this->viewerAToken)
            ->postJson("/api/v1/dashboards/{$sharedDash->id}/widgets", [
                'saved_query_id' => $sharedQuery->id,
            ])
            ->assertStatus(403);

        // Viewer CANNOT delete dashboard (403)
        $this->withAuth($this->viewerAToken)
            ->deleteJson("/api/v1/dashboards/{$sharedDash->id}")
            ->assertStatus(403);

        // Viewer CANNOT create dashboard (403)
        $this->withAuth($this->viewerAToken)
            ->postJson('/api/v1/dashboards', [
                'name' => 'Viewer Dashboard',
            ])
            ->assertStatus(403);

        // Analyst 2 cannot add another analyst's private query as a widget (404)
        $analyst2 = User::create([
            'name' => 'Analyst Sam',
            'email' => 'sam@abcelectronics.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'analyst',
        ]);
        $samToken = $analyst2->createToken('test')->plainTextToken;

        $samDash = Dashboard::create([
            'company_id' => $this->companyA->id,
            'user_id' => $analyst2->id,
            'name' => 'Sam Dashboard',
            'visibility' => 'private',
        ]);

        $this->withAuth($samToken)
            ->postJson("/api/v1/dashboards/{$samDash->id}/widgets", [
                'saved_query_id' => $privateQuery->id, // Belongs to analyst A, private!
            ])
            ->assertStatus(404);

        // Cross-company dashboard access denied (404)
        $this->withAuth($this->adminBToken)
            ->getJson("/api/v1/dashboards/{$sharedDash->id}")
            ->assertStatus(404);
    }

    public function test_database_connection_role_permissions(): void
    {
        // 1. Admin creates connection
        $createRes = $this->withAuth($this->adminAToken)
            ->postJson('/api/v1/database-connections', [
                'name' => 'Production DB',
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'prod_db',
                'username' => 'admin_user',
                'password' => 'secret123',
            ]);
        $createRes->assertStatus(201);
        $connId = $createRes->json('data.id');

        // 2. Analyst CAN view connections list for workspace queries
        $listRes = $this->withAuth($this->analystAToken)
            ->getJson('/api/v1/database-connections');
        $listRes->assertStatus(200);

        // 3. Analyst CANNOT create connection (403)
        $this->withAuth($this->analystAToken)
            ->postJson('/api/v1/database-connections', [
                'name' => 'Analyst DB',
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'db',
                'username' => 'u',
                'password' => 'p',
            ])
            ->assertStatus(403);

        // 4. Analyst CANNOT delete connection (403)
        $this->withAuth($this->analystAToken)
            ->deleteJson("/api/v1/database-connections/{$connId}")
            ->assertStatus(403);

        // 5. Viewer CANNOT view connections list (403)
        $this->withAuth($this->viewerAToken)
            ->getJson('/api/v1/database-connections')
            ->assertStatus(403);

        // 6. Viewer CANNOT delete connection (403)
        $this->withAuth($this->viewerAToken)
            ->deleteJson("/api/v1/database-connections/{$connId}")
            ->assertStatus(403);

        // 7. Admin CAN delete connection
        $this->withAuth($this->adminAToken)
            ->deleteJson("/api/v1/database-connections/{$connId}")
            ->assertStatus(200);
    }

    public function test_viewer_cannot_execute_arbitrary_workspace_query(): void
    {
        // Viewer attempts to call POST /api/v1/query -> 403
        $response = $this->withAuth($this->viewerAToken)
            ->postJson('/api/v1/query', [
                'question' => 'Show all customer orders',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ]);
    }

    public function test_analyst_can_execute_workspace_query_with_guardrails(): void
    {
        $response = $this->withAuth($this->analystAToken)
            ->postJson('/api/v1/query', [
                'question' => 'How many customers are there?',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'guardrails' => [
                    'allowed' => true,
                ],
            ]);
    }
}
