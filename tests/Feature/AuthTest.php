<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'ada@example.com');

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.abilities', ['*']);
    }

    public function test_login_with_valid_and_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-password']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'user' => ['id', 'name', 'email']]);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_guests_get_a_json_401(): void
    {
        $this->get('/api/v1/contacts')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_users_can_mint_list_and_revoke_scoped_tokens(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $created = $this->postJson('/api/v1/auth/tokens', [
            'name' => 'analytics',
            'abilities' => ['links:read'],
            'expires_in_days' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.abilities', ['links:read'])
            ->assertJsonStructure(['access_token', 'data' => ['id', 'expires_at']]);

        $this->getJson('/api/v1/auth/tokens')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson('/api/v1/auth/tokens/'.$created->json('data.id'))->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_full_access_cannot_be_granted_to_minted_tokens(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/auth/tokens', ['name' => 'x', 'abilities' => ['*']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities.0');
    }

    public function test_a_scoped_token_cannot_manage_tokens_or_contacts(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('analytics', ['links:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/contacts')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/tokens', ['name' => 'x', 'abilities' => ['links:read']])->assertForbidden();
    }
}
