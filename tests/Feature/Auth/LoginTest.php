<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_json_success(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/login', [
            'username' => 'admin',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['user' => ['id', 'name', 'username']],
            ])
            ->assertJson(['success' => true]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_invalid_credentials_returns_422(): void
    {
        User::factory()->create([
            'username' => 'admin',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJson(['success' => false]);

        $this->assertGuest();
    }

    public function test_login_with_missing_fields_returns_422(): void
    {
        $response = $this->postJson('/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/logout')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertGuest();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/logout')
            ->assertStatus(401);
    }

    public function test_login_form_returns_view(): void
    {
        $this->get('/login')
            ->assertStatus(200)
            ->assertViewIs('auth.login');
    }

    public function test_too_many_login_attempts_returns_429(): void
    {
        User::factory()->create([
            'username' => 'admin',
            'password' => 'password123',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'username' => 'admin',
                'password' => 'wrong',
            ]);
        }

        $response = $this->postJson('/login', [
            'username' => 'admin',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }
}
