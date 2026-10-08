<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionBootstrapTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_bootstrap_returns_minimal_uncached_state(): void
    {
        $this->getJson('/api/session')->assertOk()->assertExactJson(['authenticated' => false])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/health')->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_admin_bootstrap_and_explicit_logout(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/session')->assertOk()->assertExactJson(['authenticated' => true, 'type' => 'admin']);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->getJson('/api/session')->assertExactJson(['authenticated' => false]);
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_non_admin_user_does_not_bootstrap_as_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/session')->assertExactJson(['authenticated' => false]);
    }

    public function test_client_bootstrap_returns_only_public_project_uuid_and_reusable_links_remain_valid(): void
    {
        $project = Project::factory()->create();
        $plain = bin2hex(random_bytes(32));
        $token = $project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $plain)]);
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid);
        $response = $this->getJson('/api/session')->assertOk()->assertExactJson([
            'authenticated' => true, 'type' => 'client', 'project' => ['id' => $project->uuid],
        ]);
        $this->assertStringNotContainsString($plain, $response->getContent());
        $this->assertStringNotContainsString($token->token_hash, $response->getContent());
        $this->assertNull($token->expires_at);
        $this->travel(366)->days();
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid);
        $this->getJson('/api/session')->assertJsonPath('type', 'client');
    }

    public static function invalidClientStates(): array
    {
        return [['revoked'], ['disabled'], ['inactive'], ['expired'], ['missing'], ['wrong_project'], ['wrong_client']];
    }

    #[DataProvider('invalidClientStates')]
    public function test_stale_client_sessions_are_invalidated_by_both_bootstrap_and_protected_endpoints(string $state): void
    {
        $this->freezeTime();
        $project = Project::factory()->create();
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $project->uuid)]);
        $session = ['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id];
        match ($state) {
            'revoked' => $token->update(['revoked_at' => now()]),
            'disabled' => $client->update(['active' => false]),
            'inactive' => $project->update(['status' => 'inactive']),
            'expired' => $token->update(['expires_at' => now()]),
            'missing' => $token->delete(),
            'wrong_project' => $session['client_project_id'] = Project::factory()->create()->id,
            'wrong_client' => $session['project_client_id'] = Project::factory()->create()->clients()->first()->id,
        };
        $this->withSession($session)->getJson('/api/session')->assertOk()->assertExactJson(['authenticated' => false])
            ->assertSessionMissing('client_project_id')->assertSessionMissing('project_client_id')->assertSessionMissing('client_access_token_id');
        $this->withSession($session)->getJson('/api/client/project')->assertUnauthorized();
        $this->withSession($session)->getJson('/api/client/push/status')->assertUnauthorized();
        $this->withSession($session)->getJson('/api/client/tasks')->assertUnauthorized();
    }

    public function test_production_examples_recommend_finite_year_long_database_sessions_with_secure_cookies(): void
    {
        foreach (['.env.example', '.env.production.example'] as $example) {
            $contents = file_get_contents(base_path($example));
            $this->assertStringContainsString('SESSION_DRIVER=database', $contents);
            $this->assertStringContainsString('SESSION_LIFETIME=525600', $contents);
            $this->assertStringContainsString('SESSION_EXPIRE_ON_CLOSE=false', $contents);
        }
        $production = file_get_contents(base_path('.env.production.example'));
        foreach (['SESSION_SECURE_COOKIE=true', 'SESSION_HTTP_ONLY=true', 'SESSION_SAME_SITE=lax'] as $setting) {
            $this->assertStringContainsString($setting, $production);
        }
        config(['session.driver' => 'database', 'session.lifetime' => 525600, 'session.expire_on_close' => false, 'session.secure' => true]);
        $response = $this->getJson('/api/csrf')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertEqualsWithDelta(now()->addDays(365)->timestamp, $cookie->getExpiresTime(), 2);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertDatabaseCount('sessions', 1);
    }
}
