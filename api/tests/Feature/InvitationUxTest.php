<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InvitationUxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function invitation(string $email): array
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $token = bin2hex(random_bytes(32));
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $project->workspace_id, 'invited_by' => $owner->id, 'email' => $email, 'token_hash' => hash('sha256', $token), 'project_ids' => [$project->id]]);

        return [$invitation, $token, $project, $owner];
    }

    public function test_guests_get_no_account_existence_or_current_account_details(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);
        foreach (['new@example.test', 'existing@example.test'] as $email) {
            [$invitation, $token] = $this->invitation($email);
            $this->postJson('/api/invitations/resolve', ['token' => $token, 'email' => 'arbitrary@example.test'])
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('email', $email)->assertJsonPath('authenticated', false)
                ->assertJsonPath('authenticatedEmail', null)->assertJsonPath('accountMatches', null)
                ->assertJsonMissingPath('accountExists')->assertJsonMissingPath('registered')->assertJsonMissingPath('user');
        }
        $this->postJson('/api/invitations/resolve', ['email' => 'existing@example.test'])->assertUnprocessable();
    }

    public function test_wrong_account_is_identified_before_accept_but_still_rejected_by_the_server(): void
    {
        [$invitation, $token, $project, $owner] = $this->invitation('invited@example.test');
        $this->actingAs($owner)->postJson('/api/invitations/resolve', ['token' => $token])->assertOk()
            ->assertJsonPath('authenticatedEmail', $owner->email)->assertJsonPath('accountMatches', false);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertForbidden();
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_case_and_surrounding_whitespace_are_normalized_consistently_for_matching_and_acceptance(): void
    {
        [$invitation, $token, $project] = $this->invitation('invited@example.test');
        $user = User::factory()->create(['email' => ' Invited@Example.test ']);
        $this->actingAs($user)->postJson('/api/invitations/resolve', ['token' => $token])->assertOk()->assertJsonPath('accountMatches', true);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertOk();
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonPath('data.0.id', $project->uuid);
    }

    public function test_switching_account_keeps_original_invitation_and_allows_existing_user_without_membership(): void
    {
        [$invitation, $token, $project, $owner] = $this->invitation('invited@example.test');
        $user = User::factory()->create(['email' => 'Invited@Example.test', 'password' => 'Existing-password!']);
        $this->actingAs($owner)->postJson('/api/admin/logout')->assertOk();
        $this->postJson('/api/invitations/resolve', ['token' => $token])->assertOk()->assertJsonPath('authenticated', false);
        $this->postJson('/api/admin/login', ['email' => ' invited@example.test ', 'password' => 'wrong', 'invitation_token' => $token])->assertUnprocessable();
        $this->assertGuest();
        $this->postJson('/api/admin/login', ['email' => ' invited@example.test ', 'password' => 'Existing-password!', 'invitation_token' => $token])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->workspaceMembers()->count());
        $this->postJson('/api/invitations/resolve', ['token' => $token])->assertOk()->assertJsonPath('authenticatedEmail', $user->email)->assertJsonPath('accountMatches', true);
        $this->postJson('/api/invitations/accept', ['token' => $token])->assertOk()->assertSessionHas('workspace_id', $project->workspace_id);
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project->uuid);
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['invited@example.test'])->count());
    }

    public function test_expired_revoked_and_accepted_invitations_cannot_resolve_session_details_or_be_accepted(): void
    {
        foreach (['expires_at' => now()->subSecond(), 'revoked_at' => now(), 'accepted_at' => now()] as $field => $value) {
            [$invitation, $token] = $this->invitation('invited@example.test');
            $invitation->forceFill([$field => $value])->save();
            $this->postJson('/api/invitations/resolve', ['token' => $token])->assertGone()->assertJsonMissingPath('authenticatedEmail');
            $this->postJson('/api/invitations/accept', ['token' => $token])->assertGone();
        }
    }
}
