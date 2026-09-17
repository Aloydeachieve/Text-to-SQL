<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SavedQuery;
use App\Models\SemanticMetric;
use App\Models\SemanticTableClassification;
use App\Models\SemanticTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticMetricTest extends TestCase
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

        $this->companyA = Company::create(['name' => 'Acme Analytics']);
        $this->companyB = Company::create(['name' => 'Globex Corp']);

        $this->adminA = User::create([
            'name' => 'Admin Alice',
            'email' => 'admin.a@acme.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        $this->adminAToken = $this->adminA->createToken('test')->plainTextToken;

        $this->analystA = User::create([
            'name' => 'Analyst Bob',
            'email' => 'analyst.a@acme.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'analyst',
        ]);
        $this->analystAToken = $this->analystA->createToken('test')->plainTextToken;

        $this->viewerA = User::create([
            'name' => 'Viewer Charlie',
            'email' => 'viewer.a@acme.com',
            'password' => 'password123',
            'company_id' => $this->companyA->id,
            'role' => 'viewer',
        ]);
        $this->viewerAToken = $this->viewerA->createToken('test')->plainTextToken;

        $this->adminB = User::create([
            'name' => 'Admin Dave',
            'email' => 'admin.d@globex.com',
            'password' => 'password123',
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);
        $this->adminBToken = $this->adminB->createToken('test')->plainTextToken;
    }

    /**
     * Test 1: Admin can create, read, update, and delete semantic metrics.
     */
    public function test_admin_can_manage_semantic_metrics(): void
    {
        // 1. Create metric
        $createRes = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->postJson('/api/v1/semantic/metrics', [
                'name' => 'Monthly Recurring Revenue',
                'slug' => 'mrr',
                'description' => 'Sum of active recurring subscriptions',
                'definition' => 'Total active recurring monthly billing across all subscriptions.',
                'source_table' => 'payments',
                'source_column' => 'amount',
                'aggregation' => 'SUM',
                'filter_condition' => "status = 'completed'",
                'date_column' => 'paid_at',
                'is_active' => true,
                'is_source_of_truth' => true,
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Monthly Recurring Revenue')
            ->assertJsonPath('data.slug', 'mrr')
            ->assertJsonPath('data.source_table', 'payments')
            ->assertJsonPath('data.source_column', 'amount')
            ->assertJsonPath('data.aggregation', 'SUM');

        $metricId = $createRes->json('data.id');

        // 2. View metric
        $getRes = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->getJson("/api/v1/semantic/metrics/{$metricId}");
        $getRes->assertStatus(200)
            ->assertJsonPath('data.id', $metricId);

        // 3. Update metric
        $updateRes = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->patchJson("/api/v1/semantic/metrics/{$metricId}", [
                'description' => 'Updated MRR description',
                'aggregation' => 'SUM',
            ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.description', 'Updated MRR description');

        // 4. Delete metric
        $deleteRes = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->deleteJson("/api/v1/semantic/metrics/{$metricId}");
        $deleteRes->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('semantic_metrics', ['id' => $metricId]);
    }

    /**
     * Test 2: Analyst and Viewer cannot create, update, or delete metrics (RBAC).
     */
    public function test_analyst_and_viewer_cannot_modify_metrics(): void
    {
        $metric = SemanticMetric::create([
            'company_id' => $this->companyA->id,
            'name' => 'Net Sales',
            'slug' => 'net_sales',
            'definition' => 'Total completed order gross minus discounts.',
            'source_table' => 'orders',
            'source_column' => 'total_amount',
            'aggregation' => 'SUM',
            'created_by' => $this->adminA->id,
        ]);

        // Analyst create -> 403
        $this->withHeaders(['Authorization' => "Bearer {$this->analystAToken}"])
            ->postJson('/api/v1/semantic/metrics', [
                'name' => 'Test Metric',
                'definition' => 'Test definition',
                'source_table' => 'orders',
                'source_column' => 'total_amount',
                'aggregation' => 'SUM',
            ])->assertStatus(403);

        // Viewer create -> 403
        $this->withHeaders(['Authorization' => "Bearer {$this->viewerAToken}"])
            ->postJson('/api/v1/semantic/metrics', [
                'name' => 'Test Metric 2',
                'definition' => 'Test definition 2',
                'source_table' => 'orders',
                'source_column' => 'total_amount',
                'aggregation' => 'SUM',
            ])->assertStatus(403);

        // Analyst update -> 403
        $this->withHeaders(['Authorization' => "Bearer {$this->analystAToken}"])
            ->patchJson("/api/v1/semantic/metrics/{$metric->id}", ['name' => 'Hacked Metric'])
            ->assertStatus(403);

        // Viewer delete -> 403
        $this->withHeaders(['Authorization' => "Bearer {$this->viewerAToken}"])
            ->deleteJson("/api/v1/semantic/metrics/{$metric->id}")
            ->assertStatus(403);

        // Both Analyst and Viewer CAN view metrics
        $this->withHeaders(['Authorization' => "Bearer {$this->analystAToken}"])
            ->getJson('/api/v1/semantic/metrics')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->withHeaders(['Authorization' => "Bearer {$this->viewerAToken}"])
            ->getJson('/api/v1/semantic/metrics')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * Test 3: Strict Company/Tenant Isolation.
     */
    public function test_strict_tenant_isolation_for_metrics(): void
    {
        $metricA = SemanticMetric::create([
            'company_id' => $this->companyA->id,
            'name' => 'Revenue A',
            'slug' => 'revenue_a',
            'definition' => 'Company A Revenue',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'created_by' => $this->adminA->id,
        ]);

        // Company B Admin cannot see Company A metric
        $res = $this->withHeaders(['Authorization' => "Bearer {$this->adminBToken}"])
            ->getJson("/api/v1/semantic/metrics/{$metricA->id}");
        $res->assertStatus(404);

        // Company B Admin cannot update Company A metric
        $this->withHeaders(['Authorization' => "Bearer {$this->adminBToken}"])
            ->patchJson("/api/v1/semantic/metrics/{$metricA->id}", ['name' => 'Tampered'])
            ->assertStatus(404);

        // Company B list does not contain Company A metric
        $listRes = $this->withHeaders(['Authorization' => "Bearer {$this->adminBToken}"])
            ->getJson('/api/v1/semantic/metrics');
        $listRes->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * Test 4: Semantic terms mapping management.
     */
    public function test_admin_can_manage_semantic_terms(): void
    {
        $metric = SemanticMetric::create([
            'company_id' => $this->companyA->id,
            'name' => 'Gross Revenue',
            'slug' => 'gross_revenue',
            'definition' => 'All collected revenue',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'created_by' => $this->adminA->id,
        ]);

        // Create term
        $createRes = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->postJson('/api/v1/semantic/terms', [
                'term' => 'sales turnover',
                'metric_id' => $metric->id,
                'target_type' => 'metric',
                'target_name' => 'Gross Revenue',
                'definition' => 'Synonym for gross collected revenue',
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.term', 'sales turnover')
            ->assertJsonPath('data.target_type', 'metric');

        $termId = $createRes->json('data.id');

        // List terms
        $listRes = $this->withHeaders(['Authorization' => "Bearer {$this->analystAToken}"])
            ->getJson('/api/v1/semantic/terms');
        $listRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Delete term
        $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->deleteJson("/api/v1/semantic/terms/{$termId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('semantic_terms', ['id' => $termId]);
    }

    /**
     * Test 5: Table classification management.
     */
    public function test_admin_can_classify_tables(): void
    {
        $saveRes = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->postJson('/api/v1/semantic/table-classifications', [
                'table_name' => 'staging_orders',
                'classification' => 'staging',
                'description' => 'Temporary pre-ingestion staging table',
                'is_preferred_source' => false,
            ]);

        $saveRes->assertStatus(200)
            ->assertJsonPath('data.table_name', 'staging_orders')
            ->assertJsonPath('data.classification', 'staging');

        $id = $saveRes->json('data.id');

        // Viewer can read classifications
        $listRes = $this->withHeaders(['Authorization' => "Bearer {$this->viewerAToken}"])
            ->getJson('/api/v1/semantic/table-classifications');
        $listRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Admin can delete classification
        $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->deleteJson("/api/v1/semantic/table-classifications/{$id}")
            ->assertStatus(200);
    }

    /**
     * Test 6: Data lineage endpoint returns valid nodes and edges.
     */
    public function test_lineage_graph_endpoint(): void
    {
        $metric = SemanticMetric::create([
            'company_id' => $this->companyA->id,
            'name' => 'Total Revenue',
            'slug' => 'total_revenue',
            'definition' => 'Total revenue from payments',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'filter_condition' => "status = 'completed'",
            'date_column' => 'paid_at',
            'created_by' => $this->adminA->id,
        ]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$this->analystAToken}"])
            ->getJson("/api/v1/semantic/lineage?metric_id={$metric->id}");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'nodes',
                    'edges',
                ]
            ]);

        $nodes = $res->json('data.nodes');
        $this->assertNotEmpty($nodes);
        $nodeTypes = array_column($nodes, 'type');
        $this->assertContains('metric', $nodeTypes);
        $this->assertContains('table', $nodeTypes);
        $this->assertContains('column', $nodeTypes);
        $this->assertContains('filter', $nodeTypes);
    }

    /**
     * Test 7: Semantic drift detection.
     */
    public function test_semantic_drift_detection(): void
    {
        $metric = SemanticMetric::create([
            'company_id' => $this->companyA->id,
            'name' => 'Active Revenue',
            'slug' => 'active_revenue',
            'definition' => 'Revenue definition',
            'source_table' => 'payments',
            'source_column' => 'amount',
            'aggregation' => 'SUM',
            'filter_condition' => "status = 'completed'",
            'created_by' => $this->adminA->id,
        ]);

        $savedQuery = SavedQuery::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminA->id,
            'metric_id' => $metric->id,
            'semantic_snapshot' => [
                'source_table' => 'payments',
                'source_column' => 'amount',
                'aggregation' => 'SUM',
                'filter_condition' => "status = 'completed'",
            ],
            'name' => 'Q1 Revenue Query',
            'sql' => "SELECT SUM(amount) FROM payments WHERE status = 'completed'",
            'natural_language_question' => 'What is Q1 revenue?',
            'visibility' => 'company',
        ]);

        // No drift initially
        $res1 = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->getJson("/api/v1/semantic/drift/{$savedQuery->id}");
        $res1->assertStatus(200)
            ->assertJsonPath('data.has_drift', false);

        // Update metric definition (e.g. changed filter condition)
        $metric->update(['filter_condition' => "status IN ('completed', 'settled')"]);

        // Drift detected now!
        $res2 = $this->withHeaders(['Authorization' => "Bearer {$this->adminAToken}"])
            ->getJson("/api/v1/semantic/drift/{$savedQuery->id}");
        $res2->assertStatus(200)
            ->assertJsonPath('data.has_drift', true)
            ->assertJsonPath('data.metric_id', $metric->id);
    }
}
