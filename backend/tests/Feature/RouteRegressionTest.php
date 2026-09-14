<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_route_is_available_and_assigns_admin(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Regression Admin',
            'email' => 'admin@regressiontest.com',
            'password' => 'Password123!',
            'company_name' => 'Regression Analytics',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'user' => [
                    'name' => 'Regression Admin',
                    'email' => 'admin@regressiontest.com',
                    'role' => 'admin',
                    'company_name' => 'Regression Analytics',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@regressiontest.com',
            'role' => 'admin',
        ]);
    }

    public function test_login_route_is_available_and_returns_token(): void
    {
        $company = Company::create(['name' => 'Login Test Corp']);
        User::create([
            'name' => 'Login User',
            'email' => 'login@testcorp.com',
            'password' => 'Password123!',
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@testcorp.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => [
                    'email' => 'login@testcorp.com',
                    'role' => 'admin',
                ],
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_auth_me_route_is_available_and_returns_current_user(): void
    {
        $company = Company::create(['name' => 'Me Test Corp']);
        $user = User::create([
            'name' => 'Me User',
            'email' => 'me@testcorp.com',
            'password' => 'Password123!',
            'company_id' => $company->id,
            'role' => 'analyst',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => [
                    'email' => 'me@testcorp.com',
                    'role' => 'analyst',
                ],
            ]);
    }

    public function test_unauthenticated_protected_routes_remain_protected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/company/members')->assertStatus(401);
        $this->getJson('/api/v1/database-connections')->assertStatus(401);
        $this->getJson('/api/v1/saved-queries')->assertStatus(401);
        $this->getJson('/api/v1/dashboards')->assertStatus(401);
    }

    public function test_schema_route_is_available_and_returns_schema(): void
    {
        $response = $this->getJson('/api/v1/schema');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'tables',
                    'relationships',
                ],
            ]);
    }

    public function test_history_route_is_available(): void
    {
        $response = $this->getJson('/api/v1/history');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }
}
