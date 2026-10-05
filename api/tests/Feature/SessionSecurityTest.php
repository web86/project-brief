<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_csrf_rejects_missing_token_and_accepts_matching_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn () => new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $user = User::factory()->create(['password' => Hash::make('Local-test-password')]);
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Local-test-password'])->assertStatus(419);
        $this->assertGuest();
        $token = $this->getJson('/api/csrf')->assertOk()->json('token');
        $this->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Local-test-password'])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_production_session_cookie_has_secure_http_only_lax_attributes(): void
    {
        config(['session.secure' => true]);
        $response = $this->getJson('/api/csrf');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/login', ['email' => 'limited@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/admin/login', ['email' => 'limited@example.test', 'password' => 'wrong'])->assertTooManyRequests();
    }
}
