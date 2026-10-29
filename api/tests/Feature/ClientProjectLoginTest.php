<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Services\ClientInvitations;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientProjectLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function mail(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
    }

    public function test_canonical_link_requires_mailbox_proof_and_magic_link_can_be_used_again(): void
    {
        $this->mail();
        $project = Project::factory()->create(['client_email' => 'ada@example.test']);
        $client = $project->clients()->first();
        $this->getJson('/api/client/project/'.$project->uuid)->assertUnauthorized();
        $this->postJson('/api/client/project/'.$project->uuid.'/login', ['email' => 'ADA@example.test'])->assertAccepted()->assertExactJson(['accepted' => true]);
        $mail = Mail::sent(AccountActionMail::class)->first();
        $token = $client->accessTokens()->first();
        $this->assertNotNull($mail);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->get('/access/'.basename($mail->actionUrl))->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid);
        $this->getJson('/api/client/project/'.$project->uuid)->assertOk()->assertJsonPath('data.id', $project->uuid);
        $this->get('/access/'.basename($mail->actionUrl))->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid);
        $this->getJson('/api/client/me')->assertOk()->assertJsonPath('id', $client->uuid);
        Mail::assertSentCount(1);
    }

    public function test_unknown_email_and_unknown_project_have_same_response_and_no_mail(): void
    {
        $this->mail();
        $project = Project::factory()->create(['client_email' => 'ada@example.test']);
        $this->postJson('/api/client/project/'.$project->uuid.'/login', ['email' => 'other@example.test'])->assertAccepted()->assertExactJson(['accepted' => true]);
        $this->postJson('/api/client/project/00000000-0000-4000-8000-000000000000/login', ['email' => 'ada@example.test'])->assertAccepted()->assertExactJson(['accepted' => true]);
        $this->assertDatabaseCount('project_access_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_requesting_mail_does_not_revoke_a_verified_session_and_cannot_transfer_between_projects(): void
    {
        $this->mail();
        $one = Project::factory()->create(['client_email' => 'ada@example.test']);
        $two = Project::factory()->create(['client_email' => 'ada@example.test']);
        $link = app(ClientInvitations::class)->issue($one, $one->clients()->first(), now()->addDay());
        $this->postJson('/api/client/project/'.$one->uuid.'/login', ['email' => 'ada@example.test'])->assertAccepted();
        $this->assertNull(ProjectAccessToken::find($link['id'])->revoked_at);
        $this->get('/access/'.basename($link['url']));
        $this->getJson('/api/client/project/'.$two->uuid)->assertNotFound();
        $this->postJson('/api/client/project/'.$two->uuid.'/login', ['email' => 'ada@example.test'])->assertForbidden();
        $this->getJson('/api/client/project/'.$one->uuid)->assertOk();
        Mail::assertSentCount(1);
    }

    public function test_disabled_membership_and_revoked_token_block_access_immediately(): void
    {
        $this->mail();
        $project = Project::factory()->create(['client_email' => 'ada@example.test']);
        $client = $project->clients()->first();
        $link = app(ClientInvitations::class)->issue($project, $client, now()->addDay());
        $this->get('/access/'.basename($link['url']));
        ProjectAccessToken::find($link['id'])->update(['revoked_at' => now()]);
        $this->getJson('/api/client/project/'.$project->uuid)->assertUnauthorized();
        $client->update(['active' => false]);
        $this->postJson('/api/client/project/'.$project->uuid.'/login', ['email' => 'ada@example.test'])->assertAccepted();
        $this->get('/access/'.basename($link['url']))->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=invalid');
        $this->assertDatabaseCount('project_access_tokens', 1);
        Mail::assertNothingSent();
    }

    public function test_expired_link_requires_new_mail_and_failed_mail_does_not_undo_existing_access(): void
    {
        $project = Project::factory()->create(['client_email' => 'ada@example.test']);
        $client = $project->clients()->first();
        $old = app(ClientInvitations::class)->issue($project, $client, now()->subMinute());
        $this->get('/access/'.basename($old['url']))->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=expired');
        $this->postJson('/api/client/project/'.$project->uuid.'/login', ['email' => 'ada@example.test'])->assertAccepted();
        $tokens = $client->accessTokens()->get();
        $this->assertCount(2, $tokens);
        $this->assertNull($tokens[0]->revoked_at);
        $this->assertNotNull($tokens[1]->revoked_at);
    }

    public function test_public_login_is_rate_limited_and_cannot_create_memberships(): void
    {
        $this->mail();
        $project = Project::factory()->create(['client_email' => 'ada@example.test']);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/client/project/'.$project->uuid.'/login', ['email' => 'ada@example.test'])->assertAccepted();
        }
        $this->postJson('/api/client/project/'.$project->uuid.'/login', ['email' => 'ADA@example.test'])->assertTooManyRequests();
        $this->assertDatabaseCount('project_clients', 1);
        Mail::assertSentCount(3);
    }
}
