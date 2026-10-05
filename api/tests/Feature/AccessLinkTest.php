<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AccessLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function token(Project $project, array $fields = []): array
    {
        $plain = bin2hex(random_bytes(32));
        $record = $project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $plain), ...$fields]);

        return [$plain, $record];
    }

    public function test_admin_generates_link_and_stores_only_hash(): void
    {
        $project = Project::factory()->create();
        $result = $this->actingAs(User::factory()->create())->postJson('/api/admin/projects/'.$project->uuid.'/clients/'.$project->clients()->first()->uuid.'/access-links')->assertCreated();
        $plain = basename($result->json('url'));
        $this->assertSame(64, strlen($plain));
        $this->assertDatabaseHas('project_access_tokens', ['token_hash' => hash('sha256', $plain)]);
        $this->assertStringNotContainsString($plain, ProjectAccessToken::first()->getRawOriginal('token_hash'));
        $this->getJson('/api/admin/projects/'.$project->uuid)->assertJsonMissingPath('data.accessLinks.0.token_hash')->assertJsonMissingPath('data.accessLinks.0.url');
    }

    public function test_valid_link_regenerates_session_and_redirects_without_token(): void
    {
        $project = Project::factory()->create();
        [$plain,$record] = $this->token($project);
        $this->get('/api/csrf');
        $previous = session()->getId();
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid)->assertSessionHas('client_project_id', $project->id);
        $this->assertNotSame($previous, session()->getId());
        $this->assertNotNull($record->fresh()->last_used_at);
        $this->getJson('/api/client/project')->assertOk()->assertJsonPath('data.id', $project->uuid)->assertJsonMissingPath('data.clientEmail');
    }

    public function test_invalid_and_revoked_links_are_rejected(): void
    {
        $this->get('/access/'.str_repeat('a', 64))->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=invalid');
        [$plain] = $this->token(Project::factory()->create(), ['revoked_at' => now()]);
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=invalid');
        $this->getJson('/api/client/project')->assertUnauthorized();
    }

    public function test_expired_and_inactive_links_are_rejected(): void
    {
        $this->freezeTime();
        [$expired] = $this->token(Project::factory()->create(), ['expires_at' => now()->subMinute()]);
        $this->get('/access/'.$expired)->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=expired');
        [$inactive] = $this->token(Project::factory()->create(['status' => 'inactive']));
        $this->get('/access/'.$inactive)->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=unavailable');
    }

    public function test_rotate_revokes_old_token_and_existing_client_session(): void
    {
        $project = Project::factory()->create();
        [$plain,$old] = $this->token($project);
        $this->get('/access/'.$plain)->assertRedirect();
        $this->actingAs(User::factory()->create())->postJson('/api/admin/projects/'.$project->uuid.'/clients/'.$project->clients()->first()->uuid.'/access-links')->assertCreated();
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->getJson('/api/client/project')->assertUnauthorized();
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=invalid');
    }

    public function test_revoke_denies_existing_session_and_is_project_scoped(): void
    {
        $project = Project::factory()->create();
        [$plain,$token] = $this->token($project);
        $other = Project::factory()->create();
        $this->get('/access/'.$plain);
        $this->actingAs(User::factory()->create())->deleteJson('/api/admin/projects/'.$other->uuid.'/clients/'.$project->clients()->first()->uuid.'/access-links/'.$token->id)->assertNotFound();
        $this->deleteJson('/api/admin/projects/'.$project->uuid.'/clients/'.$project->clients()->first()->uuid.'/access-links/'.$token->id)->assertOk();
        $this->getJson('/api/client/project')->assertUnauthorized();
    }

    public function test_client_cannot_access_other_project_or_admin(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        [$plain] = $this->token($project);
        $this->get('/access/'.$plain);
        $this->getJson('/api/client/project/'.$other->uuid)->assertNotFound();
        $this->postJson('/api/admin/projects', ['title' => 'Запрещено'])->assertUnauthorized();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/clients/'.$project->clients()->first()->uuid.'/access-links')->assertUnauthorized();
    }

    public function test_rotated_link_opens_project_and_expiry_at_now_is_rejected(): void
    {
        $this->freezeTime();
        $project = Project::factory()->create();
        [$expired] = $this->token($project, ['expires_at' => now()]);
        $this->get('/access/'.$expired)->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=expired');
        $result = $this->actingAs(User::factory()->create())->postJson('/api/admin/projects/'.$project->uuid.'/clients/'.$project->clients()->first()->uuid.'/access-links')->assertCreated();
        $this->get('/access/'.basename($result->json('url')))->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid);
        $this->assertGuest();
        $this->getJson('/api/client/project')->assertOk();
    }
}
