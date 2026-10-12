<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'platform_role' => 'super_admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.name', 'Test Customer')
            ->assertJsonPath('user.platform_role', 'customer')
            ->assertJsonStructure(['token', 'token_type']);

        $this->assertDatabaseHas('users', [
            'email' => 'customer@example.com',
            'platform_role' => 'customer',
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_customer_can_login(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'SecurePassword123!',
        ])->assertOk()
          ->assertJsonStructure(['token', 'user']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'WrongPassword123!',
        ])->assertUnauthorized();
    }

    public function test_anonymous_user_cannot_access_profile(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_access_profile_and_logout(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('Test Android')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Clear cached authenticated users between simulated requests.
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_registration_requires_valid_data(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'invalid-email',
            'password' => '123',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors([
              'name', 'email', 'password',
          ]);
    }
}