<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClientInvitationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creation_without_invitation_does_not_issue_a_token_or_account(): void
    {
        Mail::fake();
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $users = User::count();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/clients', ['name' => 'New Client', 'email' => 'new@example.test', 'sendInvitation' => false])->assertCreated()->assertJsonPath('invitation', 'not_requested');
        $this->assertSame($users, User::count());
        $this->assertSame(0, ProjectAccessToken::count());
        Mail::assertNothingSent();
    }

    #[TestWith(['ru'])]
    #[TestWith(['en'])]
    #[TestWith(['tr'])]
    #[TestWith(['zh-CN'])]
    public function test_localized_invitation_uses_existing_client_access_and_expires_in_seven_days(string $locale): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $root = '/api/admin/projects/'.$project->uuid.'/clients';
        $this->travelTo(now()->startOfSecond());
        $this->withHeader('Accept-Language', $locale)->postJson($root, ['name' => 'Mehmet', 'email' => 'MEHMET@example.test', 'sendInvitation' => true])->assertCreated()->assertJsonPath('invitation', 'sent');
        $client = $project->clients()->where('email', 'mehmet@example.test')->firstOrFail();
        $mail = Mail::sent(AccountActionMail::class)->first();
        $this->assertSame($locale, $mail->language);
        $this->assertSame(trans('account.client_invitation_heading', [], $locale), $mail->heading);
        $this->assertSame(trans('account.client_open_project', [], $locale), $mail->actionLabel);
        $this->assertSame(trans('account.client_invitation_message', ['project' => $project->title], $locale), $mail->messageText);
        $this->assertStringContainsString('7', $mail->messageText);
        $this->assertStringNotContainsString('30', $mail->messageText);
        $this->assertStringContainsString('>'.$mail->actionLabel.'</a>', $mail->render());
        $this->assertStringContainsString('/access/', $mail->actionUrl);
        $token = $client->accessTokens()->firstOrFail();
        $plain = basename($mail->actionUrl);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(now()->addDays(7)->toISOString(), $token->expires_at->toISOString());
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/project/'.$project->uuid);
        $this->getJson('/api/client/me')->assertOk()->assertJsonPath('id', $client->uuid);
        $this->travel(8)->days();
        $this->get('/access/'.$plain)->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=expired');
    }

    public function test_other_account_mail_keeps_its_default_button_label(): void
    {
        $mail = new AccountActionMail('Reset password', 'Reset instructions', 'https://example.test/reset');
        $this->assertStringContainsString('>Reset password</a>', $mail->render());
        $this->assertNull($mail->actionLabel);
    }

    public function test_duplicate_creation_reuses_client_without_resending_or_rotating_links(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $root = '/api/admin/projects/'.$project->uuid.'/clients';
        $body = ['name' => 'Client', 'email' => 'client@example.test', 'sendInvitation' => true];
        $this->postJson($root, $body)->assertCreated();
        $this->postJson($root, [...$body, 'email' => 'CLIENT@example.test'])->assertOk()->assertJsonPath('invitation', 'existing');
        $this->assertSame(1, $project->clients()->where('email', 'client@example.test')->count());
        $this->assertSame(1, ProjectAccessToken::count());
        Mail::assertSentCount(1);
    }

    public function test_smtp_failure_preserves_client_and_explicit_retry_replaces_only_their_link(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP failed'));
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $root = '/api/admin/projects/'.$project->uuid.'/clients';
        $this->postJson($root, ['name' => 'Client', 'email' => 'client@example.test', 'sendInvitation' => true])->assertCreated()->assertJsonPath('invitation', 'failed');
        $client = $project->clients()->where('email', 'client@example.test')->firstOrFail();
        $token = $client->accessTokens()->firstOrFail();
        $this->assertTrue($token->isUsable());
        Mail::swap(new MailManager($this->app));
        Mail::fake();
        $this->postJson($root.'/'.$client->uuid.'/invitation')->assertOk()->assertJsonPath('invitation', 'sent');
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame(1, $client->accessTokens()->whereNull('revoked_at')->count());
        Mail::assertSentCount(1);
    }

    public function test_invitation_requires_email_and_permission_and_cannot_target_another_project(): void
    {
        Mail::fake();
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $root = '/api/admin/projects/'.$project->uuid.'/clients';
        $this->postJson($root, ['name' => 'No email', 'sendInvitation' => true])->assertUnprocessable();
        $this->assertSame(1, $project->clients()->count());
        $foreign = Project::factory()->create()->clients()->first();
        $this->postJson($root.'/'.$foreign->uuid.'/invitation')->assertNotFound();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $this->actingAs($developer)->postJson($root, ['name' => 'Denied', 'email' => 'denied@example.test', 'sendInvitation' => true])->assertForbidden();
        Mail::assertNothingSent();
    }
}
