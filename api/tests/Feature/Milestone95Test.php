<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Mail\ProjectActivityMail;
use App\Models\Comment;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IntegrationReadAccess;
use App\Services\ProjectNotificationService;
use App\Services\WorkspaceAgreements;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Milestone95Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function member(Project $project, bool $super = false): array
    {
        $user = User::factory()->create();
        $member = $project->workspace->members()->create(['user_id' => $user->id, 'role' => 'admin', 'status' => 'active', 'is_project_superadmin' => $super]);
        ProjectMember::create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->id, 'project_id' => $project->id]);

        return [$user, $member];
    }

    public function test_new_workspace_templates_are_complete_private_and_language_configuration_is_validated(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $state = $this->getJson('/api/admin/settings/agreement')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.published', null)->assertJsonPath('data.draft.enabledLocales', ['ru'])->assertJsonPath('data.draft.defaultLocale', 'ru')->json('data');
        foreach (WorkspaceAgreements::LOCALES as $locale) {
            $this->assertCount(20, $state['draft']['texts'][$locale]);
            $this->assertGreaterThan(1700, mb_strlen(json_encode($state['draft']['texts'][$locale], JSON_UNESCAPED_UNICODE)));
        }
        $payload = ['enabled' => false, 'publish' => true, 'requireAgain' => false, 'baseRevision' => null, 'draft' => $state['draft']];
        $legacyDraft = $payload['draft'];
        unset($legacyDraft['enabledLocales'], $legacyDraft['defaultLocale']);
        $this->putJson('/api/admin/settings/agreement', [...$payload, 'publish' => false, 'draft' => $legacyDraft])->assertOk()->assertJsonPath('data.draft.enabledLocales', ['ru']);
        $payload['draft']['enabledLocales'] = [];
        $this->putJson('/api/admin/settings/agreement', $payload)->assertUnprocessable();
        $payload['draft']['enabledLocales'] = ['en'];
        $this->putJson('/api/admin/settings/agreement', $payload)->assertUnprocessable();
        $payload['draft']['enabledLocales'] = ['ru'];
        $published = $this->putJson('/api/admin/settings/agreement', $payload)->assertOk()->json('data.published');
        $payload['baseRevision'] = $published['id'];
        $payload['publish'] = false;
        $payload['draft']['enabledLocales'] = ['ru', 'en'];
        $this->putJson('/api/admin/settings/agreement', $payload)->assertOk()->assertJsonPath('data.published.enabledLocales', ['ru']);
        $this->getJson('/api/public/workspaces/'.$project->workspace->uuid.'/agreement')->assertOk()->assertJsonPath('document.enabledLocales', ['ru']);
        $this->assertDatabaseHas('workspace_agreement_revisions', ['uuid' => $published['id'], 'workspace_id' => $project->workspace_id]);
        $this->assertSame($published, json_decode(DB::table('workspace_agreement_revisions')->where('uuid', $published['id'])->value('document'), true));
        $existing = Workspace::factory()->create(['administrator_agreement' => ['enabled' => false, 'draft' => ['date' => '2026-10-01', 'texts' => ['ru' => []]], 'published' => null]]);
        $this->assertSame([], $existing->administrator_agreement['draft']['texts']['ru']);
    }

    public function test_disabled_document_language_cannot_be_accepted_and_exact_revision_is_archived(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $draft = $this->getJson('/api/admin/settings/agreement')->json('data.draft');
        $document = $this->putJson('/api/admin/settings/agreement', ['enabled' => true, 'publish' => true, 'requireAgain' => false, 'baseRevision' => null, 'draft' => $draft])->assertOk()->json('data.published');
        [$user,$member] = $this->member($project);
        app(WorkspaceAgreements::class)->enroll($member);
        $this->actingAs($user);
        $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => 'en'])->assertUnprocessable();
        $this->getJson('/api/admin/projects')->assertForbidden();
        $this->postJson('/api/admin/agreement/accept', ['revision' => $document['id'], 'locale' => 'ru'])->assertOk();
        $this->getJson('/api/admin/projects')->assertOk();
        $this->assertDatabaseHas('workspace_agreement_acceptances', ['revision_id' => $document['id'], 'locale' => 'ru', 'workspace_id' => $project->workspace_id, 'user_id' => $user->id]);
    }

    public function test_superadministrator_is_owner_granted_scoped_revocable_and_cannot_escalate_invites(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        [$user,$member] = $this->member($project);
        $other = Project::factory()->for($project->workspace)->create();
        $base = '/api/admin/projects/'.$project->uuid;
        $path = '/api/admin/administrators/members/'.$member->id;
        $this->actingAs($user)->patchJson($base, ['title' => 'Forbidden'])->assertForbidden();
        $this->getJson('/api/admin/projects/trash')->assertForbidden();
        $this->actingAs($owner)->patchJson($path, ['isProjectSuperadmin' => true, 'projects' => [$project->uuid]])->assertOk();
        $this->actingAs($user)->patchJson($base, ['title' => 'Managed'])->assertOk();
        $this->patchJson('/api/admin/projects/'.$other->uuid, ['title' => 'Denied'])->assertNotFound();
        foreach (['/api/admin/administrators', '/api/admin/integrations', '/api/admin/settings/agreement', '/api/platform/overview'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson('/api/admin/projects', ['title' => 'Denied'])->assertForbidden();
        $this->postJson($base.'/team/invitations', ['email' => 'new@example.test', 'isProjectSuperadmin' => true])->assertUnprocessable();
        $link = $this->postJson($base.'/team/invitations', ['email' => 'new@example.test', 'generateLink' => true])->assertCreated()->json('link');
        $this->assertFalse(DeveloperInvitation::latest('id')->first()->is_project_superadmin);
        $this->postJson($base.'/trash')->assertOk();
        $this->getJson('/api/admin/projects/trash')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($base.'/permanent', ['confirmed' => true])->assertForbidden();
        $this->postJson($base.'/restore')->assertOk();
        $this->actingAs($owner)->patchJson($path, ['isProjectSuperadmin' => false, 'projects' => [$project->uuid]])->assertOk();
        $this->actingAs($user)->patchJson($base, ['title' => 'Denied'])->assertForbidden();
        $this->getJson('/api/admin/projects/trash')->assertForbidden();
        $this->actingAs($owner)->patchJson($path, ['isProjectSuperadmin' => true, 'projects' => []])->assertOk();
        $this->actingAs($user)->getJson($base.'/team')->assertNotFound();
        $this->getJson($base.'/team-chat')->assertNotFound();
    }

    public function test_team_channel_files_deduplication_read_state_and_all_public_boundaries(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        [$user,$member] = $this->member($project);
        $root = '/api/admin/projects/'.$project->uuid.'/team-chat';
        $this->actingAs($user);
        $submission = (string) Str::uuid();
        $body = ['text' => 'Private message <script>alert(1)</script>', 'submissionId' => $submission, 'attachments' => [UploadedFile::fake()->create('internal.txt', 1, 'text/plain')]];
        $response = $this->post($root, $body, ['Accept' => 'application/json'])->assertCreated();
        $uuid = $response->json('data.id');
        $file = $response->json('data.attachments.0.id');
        $this->post($root, $body, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $uuid);
        $this->postJson($root, ['text' => 'Changed', 'submissionId' => $submission])->assertConflict();
        $this->assertDatabaseCount('comments', 1);
        $this->assertSame(0, Comment::query()->count());
        $this->get($response->json('data.attachments.0.downloadUrl'))->assertOk();
        $this->getJson('/api/admin/attachments/'.$file.'/download')->assertNotFound();
        $this->actingAs($owner)->getJson($root)->assertOk()->assertJsonPath('unread', 1);
        $sequence = $this->getJson($root)->json('data.0.sequence');
        $this->postJson($root.'/read', ['through' => $sequence])->assertOk()->assertJsonPath('unread', 0);
        $this->getJson('/api/admin/projects/'.$project->uuid.'/discussions?kind=tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/projects/'.$project->uuid.'/discussions?comment='.$uuid)->assertNotFound();
        [$outsider] = $this->member(Project::factory()->for($project->workspace)->create());
        $this->actingAs($outsider)->getJson($root)->assertNotFound();
        $this->getJson($root.'/messages/'.$uuid)->assertNotFound();
        $this->get($root.'/files/'.$file.'/download')->assertNotFound();
        $client = ProjectClient::factory()->for($project)->create();
        $token = $client->accessTokens()->create(['project_id' => $project->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->postJson('/api/admin/logout');
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $this->getJson($root)->assertUnauthorized();
        $this->getJson('/api/client/project/'.$project->uuid.'/discussions?kind=tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/client/project/'.$project->uuid.'/discussions?comment='.$uuid)->assertNotFound();
        $this->get('/api/client/attachments/'.$file.'/download')->assertNotFound();
        $this->assertDatabaseMissing('notification_deliveries', ['event' => 'team.message_created', 'recipient_type' => 'client']);
    }

    public function test_verified_invitation_brand_survives_refresh_but_revocation_clears_it(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $link = $this->postJson('/api/admin/administrators/invitations', ['email' => 'brand@example.test', 'sendEmail' => false, 'generateLink' => true])->assertCreated()->json('link');
        parse_str(parse_url($link, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/admin/logout');
        $brand = $this->postJson('/api/invitations/resolve', ['token' => $fragment['token']])->assertOk()->json('branding');
        $this->postJson('/api/invitations/resolve', [])->assertOk()->assertJsonPath('branding', $brand);
        $this->getJson('/api/entry/branding?workspace_id=999')->assertOk()->assertJsonPath('branding', $brand);
        DeveloperInvitation::latest('id')->first()->update(['revoked_at' => now()]);
        $this->postJson('/api/invitations/resolve', [])->assertGone();
        $this->getJson('/api/entry/branding')->assertOk()->assertJsonPath('branding', null);
    }

    public function test_private_notifications_and_live_sync_never_include_message_text_or_client_recipients(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        [$user] = $this->member($project);
        $this->actingAs($owner);
        $cursor = $this->getJson('/api/admin/me')->json('syncCursor');
        $this->actingAs($user);
        $uuid = $this->postJson('/api/admin/projects/'.$project->uuid.'/team-chat', ['text' => 'SECRET-TEAM-BODY', 'submissionId' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $comment = Comment::withoutGlobalScope('public_channel')->where('uuid', $uuid)->firstOrFail();
        $event = new ProjectNotificationRequested($comment->id, 'team.message_created', null, 'admin', $user->id, $user->name, $comment->id);
        app(ProjectNotificationService::class)->notify($event);
        app(ProjectNotificationService::class)->notify($event);
        Mail::assertSentCount(1);
        Mail::assertSent(ProjectActivityMail::class, function ($mail) use ($owner): bool {
            $this->assertStringNotContainsString('SECRET-TEAM-BODY', $mail->render());

            return $mail->hasTo($owner->notificationEmail());
        });
        $this->actingAs($owner);
        $sync = $this->getJson('/api/admin/sync?cursor='.urlencode($cursor))->assertOk()->assertJsonPath('notifications.0.preview', '')->json();
        $this->assertStringNotContainsString('SECRET-TEAM-BODY', json_encode($sync));
        $this->assertStringContainsString('#team-', $sync['notifications'][0]['url']);
        $this->assertSame(0, IntegrationReadAccess::comments($project)->count());
        $this->assertDatabaseMissing('notification_deliveries', ['event' => 'team.message_created', 'recipient_type' => 'client']);
        $this->postJson('/api/admin/logout');
        $client = $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $clientCursor = $this->getJson('/api/client/project/'.$project->uuid)->json('data.syncCursor');
        Comment::create(['team_only' => true, 'project_id' => $project->id, 'author_type' => 'admin', 'author_user_id' => $user->id, 'text' => 'ANOTHER-PRIVATE-SECRET']);
        $this->getJson('/api/client/project/'.$project->uuid.'/sync?cursor='.urlencode($clientCursor))->assertOk()->assertJsonCount(0, 'notifications');
    }

    public function test_project_scoped_invitation_cannot_survive_revocation_of_inviter_assignment(): void
    {
        $project = Project::factory()->create();
        [$user,$member] = $this->member($project, true);
        $this->actingAs($user);
        $link = $this->postJson('/api/admin/projects/'.$project->uuid.'/team/invitations', ['email' => 'revoked-inviter@example.test', 'generateLink' => true])->assertCreated()->json('link');
        parse_str(parse_url($link, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/admin/logout');
        $this->postJson('/api/invitations/resolve', ['token' => $fragment['token']])->assertOk();
        $member->projectMemberships()->delete();
        $this->postJson('/api/invitations/resolve', [])->assertGone();
        $this->postJson('/api/invitations/accept', ['name' => 'Denied', 'password' => 'Valid-password-95', 'password_confirmation' => 'Valid-password-95'])->assertGone();
        $this->assertDatabaseMissing('users', ['email' => 'revoked-inviter@example.test']);
    }

    public function test_invitation_context_survives_password_mfa_session_rotation_and_cannot_skip_mfa(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $existing = User::factory()->owner()->create(['password' => 'Existing-password-95']);
        $this->actingAs($owner);
        $link = $this->postJson('/api/admin/administrators/invitations', ['email' => $existing->email, 'sendEmail' => false, 'generateLink' => true])->assertCreated()->json('link');
        parse_str(parse_url($link, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/admin/logout');
        $this->postJson('/api/invitations/resolve', ['token' => $fragment['token']])->assertOk();
        $this->postJson('/api/admin/login', ['email' => $existing->email, 'password' => 'Existing-password-95'])->assertOk()->assertJsonPath('state', 'pending_2fa');
        $this->postJson('/api/invitations/resolve', [])->assertOk()->assertJsonPath('workspace', $project->workspace->name)->assertJsonPath('authenticated', false);
        $this->postJson('/api/invitations/accept', [])->assertForbidden();
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $project->workspace_id, 'user_id' => $existing->id]);
        $this->postJson('/api/security/challenge', ['recovery_code' => 'test-recovery-code'])->assertOk();
        $this->postJson('/api/invitations/accept', [])->assertOk();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $project->workspace_id, 'user_id' => $existing->id, 'is_project_superadmin' => false]);
    }
}
