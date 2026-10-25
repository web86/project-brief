<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeveloperInvitationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'test@example.test']);
        $this->project = Project::factory()->create();
        $this->owner = $this->ownerFor($this->project);
    }

    private function root(?Project $project = null): string
    {
        return '/api/admin/projects/'.($project ?? $this->project)->uuid.'/team';
    }

    private function invite(string $email = 'new@example.test', ?array $projects = null): array
    {
        $this->actingAs($this->owner)->postJson($this->root().'/invitations', ['email' => $email, 'name' => 'Developer', 'projects' => $projects ?? [$this->project->uuid]])->assertCreated();
        $mail = Mail::sent(AccountActionMail::class)->last();
        parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $fragment);

        return [DeveloperInvitation::latest('id')->first(), $fragment['token']];
    }

    public function test_new_developer_registers_only_by_invitation_without_provisioning_a_workspace(): void
    {
        [$invitation,$token] = $this->invite();
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertStringNotContainsString($token, $invitation->toJson());
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subSeconds(5), now()->addDays(7)));
        $this->postJson('/api/admin/logout')->assertOk();
        $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'New developer', 'password' => 'Long-new-password', 'password_confirmation' => 'Long-new-password'])->assertOk();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('project_members', 1);
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->project->uuid);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertGone();
    }

    public function test_existing_account_requires_login_and_never_creates_a_duplicate(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test', 'password' => 'Existing-password']);
        [$invitation,$token] = $this->invite($user->email);
        $this->postJson('/api/admin/logout');
        $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'Impostor', 'password' => 'Attacker-password', 'password_confirmation' => 'Attacker-password'])->assertUnauthorized();
        $this->assertDatabaseCount('users', 2);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'Existing-password', 'invitation_token' => $token])->assertOk();
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_invitation_cannot_be_accepted_by_a_different_authenticated_account(): void
    {
        [$invitation,$token] = $this->invite();
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertForbidden();
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_expired_revoked_and_rotated_tokens_do_not_grant_access(): void
    {
        [$invitation,$old] = $this->invite();
        $this->postJson($this->root().'/invitations/'.$invitation->uuid.'/resend')->assertOk();
        $this->postJson('/api/invitations/resolve', ['token' => $old])->assertGone();
        parse_str(parse_url(Mail::sent(AccountActionMail::class)->last()->actionUrl, PHP_URL_FRAGMENT), $fragment);
        $token = $fragment['token'];
        $this->postJson('/api/invitations/resolve', ['token' => $token])->assertOk();
        $invitation->fresh()->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertGone();
        $this->postJson($this->root().'/invitations/'.$invitation->uuid.'/resend')->assertOk();
        $this->deleteJson($this->root().'/invitations/'.$invitation->uuid)->assertOk();
        $this->postJson($this->root().'/invitations/'.$invitation->uuid.'/resend')->assertGone();
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_existing_member_gets_another_project_and_two_workspaces_on_the_same_account(): void
    {
        $second = Project::factory()->create(['workspace_id' => $this->project->workspace_id]);
        $other = Project::factory()->create();
        $otherOwner = $this->ownerFor($other);
        $user = User::factory()->memberOf($other->workspace)->create(['email' => 'developer@example.test']);
        [$invitation,$token] = $this->invite($user->email);
        $this->actingAs($user)->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
        [$repeat,$next] = $this->invite($user->email, [$second->uuid]);
        $this->actingAs($user)->postJson('/api/invitations/accept', ['token' => $next])->assertOk();
        $this->assertSame(2, $user->workspaceMembers()->count());
        $this->assertDatabaseCount('users', 3);
        $this->getJson('/api/admin/workspaces')->assertOk()->assertJsonCount(2, 'data');
        $member = $user->workspaceMembers()->where('workspace_id', $this->project->workspace_id)->firstOrFail();
        $this->assertSame(2, $member->projectMemberships()->count());
        $this->assertSame('admin', $member->role);
        $this->actingAs($this->owner)->patchJson($this->root().'/members/'.$member->id, ['projects' => [$second->uuid]])->assertOk();
        $this->actingAs($user)->withSession(['workspace_id' => $this->project->workspace_id])->getJson('/api/admin/projects/'.$this->project->uuid)->assertNotFound();
        $this->getJson('/api/admin/projects/'.$second->uuid)->assertOk();
        $this->assertFalse(ProjectAccess::recipients($this->project)->whereKey($user->id)->exists());
        $this->assertTrue(ProjectAccess::recipients($second)->whereKey($user->id)->exists());
        $this->actingAs($this->owner)->deleteJson($this->root().'/members/'.$member->id)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertTrue($user->workspaceMembers()->where('workspace_id', $other->workspace_id)->active()->exists());
    }

    public function test_only_current_workspace_owner_can_manage_members_and_cannot_remove_an_owner(): void
    {
        $developer = User::factory()->memberOf($this->project->workspace)->create();
        $member = $developer->workspaceMembers()->first();
        $this->project->members()->create(['workspace_id' => $this->project->workspace_id, 'workspace_member_id' => $member->id]);
        $this->actingAs($developer)->getJson($this->root())->assertForbidden();
        $this->postJson($this->root().'/invitations', ['email' => 'hacker@example.test', 'projects' => [$this->project->uuid]])->assertForbidden();
        $this->patchJson($this->root().'/members/'.$member->id, ['projects' => [$this->project->uuid], 'role' => 'owner'])->assertForbidden();
        $ownerMember = $this->owner->workspaceMembers()->first();
        $this->actingAs($this->owner)->deleteJson($this->root().'/members/'.$ownerMember->id)->assertForbidden();
        $this->patchJson($this->root().'/members/'.$ownerMember->id, ['projects' => []])->assertForbidden();
        $foreign = Project::factory()->create();
        $this->postJson($this->root().'/invitations', ['email' => 'hacker@example.test', 'projects' => [$foreign->uuid]])->assertUnprocessable();
        $this->patchJson($this->root().'/members/'.$member->id, ['projects' => [$foreign->uuid]])->assertUnprocessable();
        $this->getJson($this->root($foreign))->assertNotFound();
        $this->assertDatabaseHas('workspace_members', ['id' => $ownerMember->id, 'role' => 'owner', 'status' => 'active']);
    }

    public function test_invitation_mail_is_never_written_to_log_transport_and_requests_are_limited(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs($this->owner)->postJson($this->root().'/invitations', ['email' => 'new@example.test', 'projects' => [$this->project->uuid]])->assertStatus(503);
        Mail::assertNothingSent();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($this->root().'/invitations', ['email' => 'new@example.test', 'projects' => [$this->project->uuid]])->assertStatus(503);
        }
        $this->postJson($this->root().'/invitations', ['email' => 'new@example.test', 'projects' => [$this->project->uuid]])->assertTooManyRequests();
    }
}
