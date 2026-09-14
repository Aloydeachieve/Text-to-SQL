<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantDatabaseConnectionTest extends TestCase
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

        $this->companyA = Company::create(['name' => 'Company A Corp']);
        $this->userA = User::create([
            'name' => 'User A',
            'email' => 'usera@companya.com',
            'password' => 'Password123!',
            'company_id' => $this->companyA->id,
        ]);
        $this->tokenA = $this->userA->createToken('token-a')->plainTextToken;

        $this->companyB = Company::create(['name' => 'Company B Ltd']);
        $this->userB = User::create([
            'name' => 'User B',
            'email' => 'userb@companyb.com',
            'password' => 'Password123!',
            'company_id' => $this->companyB->id,
        ]);
        $this->tokenB = $this->userB->createToken('token-b')->plainTextToken;
    }

    /**
     * Test 1: Unsupported database driver is rejected with 422
     */
    public function test_unsupported_database_driver_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/database-connections', [
                'name' => 'Invalid Connection',
                'driver' => 'sqlite', // Only mysql, pgsql supported
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'test_db',
                'username' => 'root',
                'password' => 'secret',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['driver']);
    }

    /**
     * Test 2: Database passwords are encrypted at rest and never exposed in responses
     */
    public function test_database_credentials_are_encrypted_at_rest_and_never_returned(): void
    {
        $rawPassword = 'MyVerySecretPassword123!';

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/database-connections', [
                'name' => 'Analytics MySQL Replica',
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => env('DB_DATABASE', 'text_to_sql'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => $rawPassword,
            ]);

        $response->assertStatus(201);
        $response->assertJsonMissing(['password']);
        $connectionId = $response->json('data.id');

        // Verify direct database storage is NOT plaintext
        $rawRow = DB::table('database_connections')->where('id', $connectionId)->first();
        $this->assertNotEquals($rawPassword, $rawRow->password);

        // Verify it can be decrypted using Laravel Crypt
        $decrypted = Crypt::decryptString($rawRow->password);
        $this->assertEquals($rawPassword, $decrypted);

        // Verify model access decrypts it
        $connection = DatabaseConnection::find($connectionId);
        $this->assertEquals($rawPassword, $connection->password);

        // Verify toArray() and toJson() hide the password
        $this->assertArrayNotHasKey('password', $connection->toArray());
        $this->assertStringNotContainsString($rawPassword, $connection->toJson());

        // Verify show endpoint hides password
        $showResponse = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/database-connections/{$connectionId}");
        $showResponse->assertStatus(200);
        $showResponse->assertJsonMissing(['password']);
    }

    /**
     * Test 3: Tenant Isolation - User B cannot view User A's connections
     */
    public function test_tenant_isolation_prevents_viewing_other_companies_connections(): void
    {
        $connectionA = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Company A Production',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'company_a_db',
            'username' => 'admin_a',
            'password' => 'secret_a',
            'status' => 'connected',
        ]);

        // User B attempts to access Company A's connection details
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->getJson("/api/v1/database-connections/{$connectionA->id}");

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Database connection not found or unauthorized.',
        ]);

        // User B lists connections - must NOT see Company A's connection
        $listResponse = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->getJson('/api/v1/database-connections');

        $listResponse->assertStatus(200);
        $this->assertCount(0, $listResponse->json('data'));
    }

    /**
     * Test 4: Tenant Isolation - User B cannot delete User A's connections
     */
    public function test_tenant_isolation_prevents_deleting_other_companies_connections(): void
    {
        $connectionA = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Company A Mission Critical DB',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'critical_db',
            'username' => 'admin_a',
            'password' => 'secret_a',
            'status' => 'connected',
        ]);

        // User B attempts to delete Company A's connection
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->deleteJson("/api/v1/database-connections/{$connectionA->id}");

        $response->assertStatus(404);

        // Verify connection is NOT deleted in database
        $this->assertDatabaseHas('database_connections', [
            'id' => $connectionA->id,
            'company_id' => $this->companyA->id,
        ]);
    }

    /**
     * Test 5: Test connection endpoint with invalid credentials returns safe generic message
     */
    public function test_test_connection_with_invalid_credentials_returns_safe_error(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/database-connections/test', [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'nonexistent_database_xyz_123',
                'username' => 'invalid_user',
                'password' => 'wrong_password',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Failed to connect to the database server. Please verify your host, port, credentials, and network firewall settings.',
        ]);
        // Confirm no raw stack trace or credentials in response
        $this->assertStringNotContainsString('wrong_password', $response->getContent());
        $this->assertStringNotContainsString('PDOException', $response->getContent());
    }

    /**
     * Test 6: Successful test connection for valid local MySQL database
     */
    public function test_test_connection_succeeds_for_valid_local_mysql(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->postJson('/api/v1/database-connections/test', [
                'driver' => 'mysql',
                'host' => config('database.connections.mysql.host', '127.0.0.1'),
                'port' => (int) config('database.connections.mysql.port', 3306),
                'database' => config('database.connections.mysql.database', 'text_to_sql'),
                'username' => config('database.connections.mysql.username', 'root'),
                'password' => (string) config('database.connections.mysql.password', ''),
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Database connection successful.',
        ]);
    }

    /**
     * Test 7: Schema introspection returns normalized tables, columns, and relationships
     */
    public function test_schema_introspection_returns_normalized_metadata(): void
    {
        $connection = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Company A Store DB',
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host', '127.0.0.1'),
            'port' => (int) config('database.connections.mysql.port', 3306),
            'database' => config('database.connections.mysql.database', 'text_to_sql'),
            'username' => config('database.connections.mysql.username', 'root'),
            'password' => (string) config('database.connections.mysql.password', ''),
            'status' => 'connected',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenA}")
            ->getJson("/api/v1/database-connections/{$connection->id}/schema");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'tables' => [
                    '*' => [
                        'name',
                        'columns' => [
                            '*' => ['name', 'type'],
                        ],
                    ],
                ],
                'relationships',
            ],
        ]);

        $tables = collect($response->json('data.tables'))->pluck('name')->toArray();
        $this->assertContains('customers', $tables);
        $this->assertContains('orders', $tables);
        $this->assertContains('products', $tables);
    }

    /**
     * Test 8: Tenant Isolation - User B cannot introspect Company A's schema
     */
    public function test_tenant_isolation_prevents_introspecting_other_companies_schema(): void
    {
        $connectionA = DatabaseConnection::create([
            'company_id' => $this->companyA->id,
            'name' => 'Company A Secret DB',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'secret_a_db',
            'username' => 'root',
            'password' => '',
            'status' => 'connected',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenB}")
            ->getJson("/api/v1/database-connections/{$connectionA->id}/schema");

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Database connection not found or unauthorized.',
        ]);
    }
}
