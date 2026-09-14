<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_creates_tenant_company(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice Founder',
            'email' => 'alice@acmecorp.com',
            'password' => 'SecurePass123!',
            'company_name' => 'Acme Corp',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'token',
            'user' => [
                'id',
                'name',
                'email',
                'company' => ['id', 'name'],
            ],
        ]);
        $response->assertJsonMissing(['password']);

        $this->assertDatabaseHas('companies', [
            'name' => 'Acme Corp',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'alice@acmecorp.com',
            'name' => 'Alice Founder',
        ]);

        $user = User::where('email', 'alice@acmecorp.com')->first();
        $company = Company::where('name', 'Acme Corp')->first();
        $this->assertEquals($company->id, $user->company_id);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $company = Company::create(['name' => 'Beta Logistics']);
        $user = User::create([
            'name' => 'Bob Operator',
            'email' => 'bob@betalogistics.com',
            'password' => 'Password999!',
            'company_id' => $company->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@betalogistics.com',
            'password' => 'Password999!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'token',
            'user' => [
                'id',
                'name',
                'email',
                'company' => ['id', 'name'],
            ],
        ]);
        $response->assertJsonMissing(['password']);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $company = Company::create(['name' => 'Gamma Health']);
        User::create([
            'name' => 'Charlie Doctor',
            'email' => 'charlie@gamma.com',
            'password' => 'CorrectPass123!',
            'company_id' => $company->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'charlie@gamma.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'The provided credentials do not match our records.',
        ]);
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $company = Company::create(['name' => 'Delta AI']);
        $user = User::create([
            'name' => 'David Engineer',
            'email' => 'david@delta.ai',
            'password' => 'Password123!',
            'company_id' => $company->id,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => 'David Engineer',
                'email' => 'david@delta.ai',
                'company' => [
                    'id' => $company->id,
                    'name' => 'Delta AI',
                ],
            ],
        ]);
        $response->assertJsonMissing(['password']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(401);
    }

    public function test_user_can_logout_and_revokes_token(): void
    {
        $company = Company::create(['name' => 'Echo Software']);
        $user = User::create([
            'name' => 'Eve Dev',
            'email' => 'eve@echo.io',
            'password' => 'Password123!',
            'company_id' => $company->id,
        ]);

        $token = $user->createToken('logout-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Successfully logged out.',
        ]);

        // Attempting to access protected route with revoked token must fail
        $retry = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');
        $retry->assertStatus(401);
    }
}
