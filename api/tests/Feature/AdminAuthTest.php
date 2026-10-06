<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Long-local-password')]);
        $this->withSession(['client_project_id' => 123, 'project_client_id' => 456, 'client_access_token_id' => 789]);
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Long-local-password'])->assertOk()->assertJsonPath('user.role', 'admin')->assertSessionMissing('client_project_id')->assertSessionMissing('project_client_id')->assertSessionMissing('client_access_token_id');
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/admin/me')->assertOk()->assertJsonMissingPath('user.password');
        $this->postJson('/api/admin/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected_with_422(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonPath('code', 'invalid_credentials');
        $this->assertGuest();
    }

    public function test_guest_cannot_access_admin_with_401(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_non_admin_cannot_use_admin_routes_with_403(): void
    {
        $user = User::factory()->create(['role' => 'other']);
        $this->actingAs($user)->getJson('/api/admin/me')->assertForbidden();
    }

    public function test_public_registration_does_not_exist(): void
    {
        $this->postJson('/register')->assertNotFound();
    }
}
