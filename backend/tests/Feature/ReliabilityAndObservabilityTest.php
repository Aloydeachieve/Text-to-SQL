<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Models\User;
use App\Services\DatabaseConnectionManager;
use App\Services\QueryInterpretationService;
use App\Services\SqlExecutorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReliabilityAndObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected User $adminA;
    protected User $analystA;
    protected User $viewerA;
    protected string $adminTokenA;
    protected string $analystTokenA;
    protected string $viewerTokenA;

    protected Company $companyB;
    protected User $adminB;
    protected string $adminTokenB;

    protected DatabaseConnection $connectionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Acme Corp']);
        $this->adminA = User::create([
            'name' => 'Acme Admin',
            'email' => 'admin@acme.com',
            'password' => 'Password123!',
            'role' => 'admin',
            'company_id' => $this->companyA->id,
        ]);
        $this->adminTokenA = $this->adminA->createToken('admin-a')->plainTextToken;

        $this->analystA = User::create([
            'name' => 'Acme Analyst',
            'email' => 'analyst@acme.com',
            'password' => 'Password123!',
            'role' => 'analyst',
            'company_id' => $this->companyA->id,
        ]);
        $this->analystTokenA = $this->analystA->createToken('analyst-a')->plainTextToken;

        $this->viewerA = User::create([
            'name' => 'Acme Viewer',
            'email' => 'viewer@acme.com',
            'password' => 'Password123!',
            'role' => 'viewer',
            'company_id' => $this->companyA->id,
        ]);
        $this->viewerTokenA = $this->viewerA->createToken('viewer-a')->plainTextToken;

        $this->companyB = Company::create(['name' => 'Beta Inc']);
        $this->adminB = User::create([
            'name' => 'Beta Admin',
            'email' => 'admin@beta.com',
            'password' => 'Password123!',
            'role' => 'admin',
            'company_id' => $this->companyB->id,
        ]);
        $this->adminTokenB = $this->adminB->createToken('admin-b')->plainTextToken;

        $this->connectionA = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Acme Analytics DB',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'acme_analytics',
            'username' => 'acme_user',
            'password' => 'secret_encrypted_pass',
            'is_active' => true,
        ]);
    }

    protected function withAuth(string $token): static
    {
        if (app()->has('auth')) {
            app('auth')->forgetGuards();
        }
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Test 1: Liveness /api/health responds quickly without touching customer DBs.
     */
    public function test_liveness_health_endpoint(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
            ])
            ->assertJsonStructure([
                'status',
                'timestamp',
                'environment',
            ]);

        $this->assertTrue($response->headers->has('X-Request-ID'));
    }

    /**
     * Test 2: Readiness /api/ready verifies system components and isolates customer DBs.
     */
    public function test_readiness_endpoint_checks_database_and_cache(): void
    {
        $response = $this->getJson('/api/ready');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ready',
                'checks' => [
                    'database' => 'ok',
                    'cache' => 'ok',
                ],
            ]);
    }

    /**
     * Test 3: Request ID middleware generates and echoes request correlation tokens.
     */
    public function test_request_id_middleware_attaches_and_echoes_header(): void
    {
        // Case A: No client request ID -> backend generates one
        $resA = $this->getJson('/api/health');
        $this->assertTrue($resA->headers->has('X-Request-ID'));
        $reqIdA = $resA->headers->get('X-Request-ID');
        $this->assertStringStartsWith('req_', $reqIdA);

        // Case B: Client supplies X-Request-ID -> backend echoes sanitized ID
        $customId = 'req_custom_trace_9876543210';
        $resB = $this->withHeader('X-Request-ID', $customId)->getJson('/api/health');
        $this->assertEquals($customId, $resB->headers->get('X-Request-ID'));
    }

    /**
     * Test 4: Database connection health check with latency measurement and tenant isolation.
     */
    public function test_database_connection_health_endpoint_and_tenant_isolation(): void
    {
        // Mock connection manager checkHealth
        $this->mock(DatabaseConnectionManager::class, function ($mock) {
            $mock->shouldReceive('checkHealth')
                ->once()
                ->andReturn([
                    'healthy' => true,
                    'status' => 'healthy',
                    'latency_ms' => 12.4,
                    'message' => 'Connection successful (12.4ms ping).',
                    'driver' => 'mysql',
                    'timestamp' => now()->toIso8601String(),
                ]);
        });

        // Admin of Company A can check health
        $response = $this->withAuth($this->adminTokenA)
            ->getJson("/api/v1/database-connections/{$this->connectionA->id}/health");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'healthy' => true,
                    'status' => 'healthy',
                    'latency_ms' => 12.4,
                ],
            ]);

        // Viewer is forbidden
        $viewerResponse = $this->withAuth($this->viewerTokenA)
            ->getJson("/api/v1/database-connections/{$this->connectionA->id}/health");

        $viewerResponse->assertStatus(403);

        // Cross-tenant access: Admin of Company B cannot check health of Company A connection
        $crossResponse = $this->withAuth($this->adminTokenB)
            ->getJson("/api/v1/database-connections/{$this->connectionA->id}/health");

        $crossResponse->assertStatus(404);
    }

    /**
     * Test 5: Query complexity risk detector identifies unbounded and cartesian queries.
     */
    public function test_query_complexity_risk_detection(): void
    {
        $interpreter = app(QueryInterpretationService::class);

        // A. Low risk query with filter and limit
        $cleanQuery = $interpreter->interpret("SELECT id, name FROM users WHERE company_id = 1 LIMIT 50");
        $this->assertEquals('low', $cleanQuery['risk_level']);
        $this->assertEmpty($cleanQuery['risk_reasons']);

        // B. Unbounded SELECT * query
        $unboundedQuery = $interpreter->interpret("SELECT * FROM users");
        $this->assertEquals('medium', $unboundedQuery['risk_level']);
        $this->assertContains('Unbounded SELECT * without a LIMIT clause may retrieve thousands of columns and rows, impacting browser performance.', $unboundedQuery['risk_reasons']);

        // C. Cartesian cross join query
        $cartesianQuery = $interpreter->interpret("SELECT * FROM users, orders");
        $this->assertEquals('high', $cartesianQuery['risk_level']);
        $this->assertContains('Potential Cartesian product (CROSS JOIN) detected without explicit ON predicates, which can cause combinatorial row multiplication.', $cartesianQuery['risk_reasons']);

        // D. Multi-table join without WHERE filters
        $unfilteredJoin = $interpreter->interpret("SELECT u.name, o.id FROM users u JOIN orders o ON u.id = o.user_id");
        $this->assertEquals('medium', $unfilteredJoin['risk_level']);
        $this->assertContains('Multi-table join query contains no WHERE filter constraints or LIMIT clause.', $unfilteredJoin['risk_reasons']);
    }

    /**
     * Test 6: Result truncation truncates oversized datasets safely.
     */
    public function test_result_size_truncation_limits(): void
    {
        $executor = app(SqlExecutorService::class);

        // Set maximum rows to 5 for test assertion
        Config::set('reliability.max_query_rows', 5);

        // Create 10 dummy users to query
        for ($i = 1; $i <= 10; $i++) {
            User::create([
                'name' => "User {$i}",
                'email' => "user{$i}@test.com",
                'password' => 'secret',
                'company_id' => $this->companyA->id,
            ]);
        }

        // Execution on default test database connection
        $result = $executor->execute("SELECT id, name, email FROM users");

        $this->assertTrue($result['success']);
        $this->assertTrue($result['truncated']);
        $this->assertEquals(5, $result['returned_rows']);
        $this->assertEquals(5, $result['limit']);
        $this->assertGreaterThanOrEqual(10, $result['total_rows']);
        $this->assertCount(5, $result['results']);
    }

    /**
     * Test 7: Rate limiter responds with 429 and standard error envelope.
     */
    public function test_auth_rate_limiting_envelope(): void
    {
        // Hit login endpoint with invalid credentials repeatedly to trigger rate limiter
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'invalid@test.com',
                'password' => 'wrong-pass',
            ]);
        }

        // 11th request should be throttled
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'invalid@test.com',
            'password' => 'wrong-pass',
        ]);

        $response->assertStatus(429)
            ->assertJson([
                'success' => false,
                'error_code' => 'RATE_LIMITED',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'error_code',
                'retry_after',
                'request_id',
            ]);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-Request-ID'));
    }

    /**
     * Test 8: Production error handling sanitizes 500 exceptions when APP_DEBUG=false.
     */
    public function test_production_error_masking_when_debug_false(): void
    {
        Config::set('app.debug', false);

        // Register a temporary test route that throws an unhandled exception
        Route::get('/api/test-fault-route', function () {
            throw new \RuntimeException('Database password leaked: secret_pass_123');
        });

        $response = $this->getJson('/api/test-fault-route');

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'An unexpected internal error occurred. Please contact support with the request ID.',
                'error_code' => 'INTERNAL_ERROR',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'error_code',
                'request_id',
            ]);

        // Verify the raw exception message is NOT leaked in the response
        $this->assertStringNotContainsString('Database password leaked', $response->getContent());
        $this->assertStringNotContainsString('secret_pass_123', $response->getContent());
        $this->assertTrue($response->headers->has('X-Request-ID'));
    }

    /**
     * Test 9: QueryLog records reliability fields (request_id, risk_level, rows_returned, truncated).
     */
    public function test_query_log_records_reliability_and_observability_fields(): void
    {
        $log = QueryLog::create([
            'request_id' => 'req_abc_12345',
            'user_id' => $this->adminA->id,
            'company_id' => $this->companyA->id,
            'database_connection_id' => $this->connectionA->id,
            'source' => 'workspace',
            'question' => 'How many users do we have?',
            'generated_sql' => 'SELECT count(*) as total FROM users',
            'passed_guardrails' => true,
            'execution_status' => 'success',
            'execution_time_ms' => 14.5,
            'rows_returned' => 1,
            'truncated' => false,
            'risk_level' => 'low',
            'confidence_score' => 0.95,
        ]);

        $this->assertDatabaseHas('query_logs', [
            'id' => $log->id,
            'request_id' => 'req_abc_12345',
            'risk_level' => 'low',
            'rows_returned' => 1,
            'truncated' => 0,
            'error_code' => null,
        ]);
    }
}
