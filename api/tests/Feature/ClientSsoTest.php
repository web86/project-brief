<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\User;
use App\Services\ClientInvitations;
use App\Services\ClientSso;
use App\Services\IntegrationTokens;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClientSsoTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const API = '/api/v1/client-sso/handoffs';

    private const ORIGIN = 'https://studio.example.test';

    private function fixture(): array
    {
        $project = Project::factory()->create(['client_email' => 'ada@example.test']);
        $source = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Studio', 'sso_enabled' => true, 'sso_origin' => self::ORIGIN, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('integration_source_projects')->insert(['workspace_id' => $project->workspace_id, 'integration_source_id' => $source, 'project_id' => $project->id]);
        $token = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Client area', 'scopes' => ['clients:sso'], 'sourceId' => $source]);

        return [$project, $source, $token];
    }

    private function input(Project $project): array
    {
        return ['projectId' => $project->uuid, 'externalUserId' => 'user-42', 'email' => 'ADA@example.test', 'emailVerified' => true];
    }

    private function headers(array $token): array
    {
        return ['Authorization' => 'Bearer '.$token['secret']];
    }

    private function issue(Project $project, array $token): string
    {
        return $this->postJson(self::API, $this->input($project), $this->headers($token))->assertCreated()->json('data.code');
    }

    private function complete(string $code)
    {
        return $this->withCookie(ClientSso::COOKIE, $code)->get('/client-sso/complete');
    }

    public function test_issues_only_a_hashed_sixty_second_code_and_never_creates_projects_or_memberships(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$project, $source, $token] = $this->fixture();
        $response = $this->postJson(self::API, $this->input($project), $this->headers($token))->assertCreated();
        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $code);
        $this->assertSame(now()->addSeconds(60)->toISOString(), $response->json('data.expiresAt'));
        $this->assertSame(rtrim(config('projectbrief.frontend_url'), '/').'/client-sso/enter', $response->json('data.postUrl'));
        $this->assertDatabaseHas('client_sso_handoffs', ['code_hash' => hash('sha256', $code), 'project_id' => $project->id, 'integration_source_id' => $source]);
        $this->assertStringNotContainsString($code, json_encode(DB::table('client_sso_handoffs')->first()));
        foreach (['projects', 'project_clients', 'client_sso_bindings'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        foreach (['workspace_clients', 'project_access_tokens', 'integration_requests', 'provisioning_operations', 'webhook_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->getJson('/api/client/project/'.$project->uuid)->assertUnauthorized();
    }

    public function test_stateless_origin_checked_bridge_has_no_code_in_html_or_session_cookie_then_enters_project(): void
    {
        [$project, , $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $bridge = $this->post('/client-sso/enter', ['code' => $code], ['Origin' => self::ORIGIN])->assertOk()->assertDontSee($code);
        $this->assertCount(1, $bridge->headers->getCookies());
        $this->assertSame(ClientSso::COOKIE, $bridge->headers->getCookies()[0]->getName());
        $this->assertTrue($bridge->headers->getCookies()[0]->isHttpOnly());
        $this->assertSame('lax', $bridge->headers->getCookies()[0]->getSameSite());
        $this->assertStringContainsString("default-src 'none'", $bridge->headers->get('Content-Security-Policy'));
        $bridge->assertHeader('Referrer-Policy', 'no-referrer');
        $old = session()->getId();
        $this->complete($code)->assertStatus(303)->assertRedirect(rtrim(config('projectbrief.frontend_url'), '/').'/project/'.$project->uuid);
        $this->assertNotSame($old, session()->getId());
        $this->assertAuthenticatedAsClient($project);
        $this->assertDatabaseCount('project_access_tokens', 1);
        $this->assertNotNull(DB::table('client_sso_handoffs')->first()->consumed_at);
        $this->assertNotSame(hash('sha256', $code), ProjectAccessToken::first()->token_hash);
    }

    private function assertAuthenticatedAsClient(Project $project): void
    {
        $this->getJson('/api/client/project/'.$project->uuid)->assertOk()->assertJsonPath('data.id', $project->uuid);
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_replay_and_expiration_are_refused_and_magic_link_still_works(): void
    {
        [$project, , $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $this->complete($code)->assertStatus(303);
        $access = session('client_access_token_id');
        $this->complete($code)->assertRedirectContains('reason=sso');
        $this->assertSame($access, session('client_access_token_id'));
        $expired = $this->issue($project, $token);
        $this->travel(61)->seconds();
        $this->complete($expired)->assertRedirectContains('reason=sso');
        $this->assertDatabaseCount('project_access_tokens', 1);
        $link = app(ClientInvitations::class)->issue($project, $project->clients()->first(), now()->addMinutes(30), false);
        $this->get('/access/'.basename($link['url']))->assertRedirect(rtrim(config('projectbrief.frontend_url'), '/').'/project/'.$project->uuid);
        $this->assertAuthenticatedAsClient($project);
    }

    #[TestWith([false])] #[TestWith(['true'])] #[TestWith([1])] #[TestWith([null])]
    public function test_email_verification_requires_json_boolean_true(mixed $verified): void
    {
        [$project, , $token] = $this->fixture();
        $input = $this->input($project);
        $input['emailVerified'] = $verified;
        $this->postJson(self::API, $input, $this->headers($token))->assertUnprocessable();
        $this->assertDatabaseCount('client_sso_handoffs', 0);
    }

    public function test_scope_source_grants_and_workspace_isolation_are_mandatory(): void
    {
        [$project, $source, $token] = $this->fixture();
        $this->postJson(self::API, $this->input($project))->assertUnauthorized();
        $read = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Old', 'scopes' => ['projects:read']]);
        $this->postJson(self::API, $this->input($project), $this->headers($read))->assertForbidden();
        $other = Project::factory()->create(['client_email' => 'ada@example.test']);
        $this->postJson(self::API, $this->input($other), $this->headers($token))->assertNotFound();
        $same = Project::factory()->for($project->workspace)->create(['client_email' => 'ada@example.test']);
        $this->postJson(self::API, $this->input($same), $this->headers($token))->assertForbidden();
        DB::table('integration_sources')->where('id', $source)->update(['sso_enabled' => false]);
        $this->postJson(self::API, $this->input($project), $this->headers($token))->assertForbidden();
    }

    public function test_identity_binding_survives_token_replacement_but_never_rebinds_email_or_external_id(): void
    {
        [$project, $source, $token] = $this->fixture();
        $this->issue($project, $token);
        DB::table('integration_tokens')->where('uuid', $token['id'])->update(['revoked_at' => now()]);
        $replacement = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'New', 'scopes' => ['clients:sso'], 'sourceId' => $source]);
        $this->issue($project, $replacement);
        $this->assertDatabaseCount('client_sso_bindings', 1);
        $input = $this->input($project);
        $input['externalUserId'] = 'another-id';
        $this->postJson(self::API, $input, $this->headers($replacement))->assertConflict();
        $project->clients()->update(['email' => 'new@example.test']);
        $input = $this->input($project);
        $input['email'] = 'new@example.test';
        $this->postJson(self::API, $input, $this->headers($replacement))->assertConflict();
    }

    #[TestWith(['membership'])] #[TestWith(['project'])] #[TestWith(['source'])] #[TestWith(['token'])] #[TestWith(['grant'])] #[TestWith(['email'])]
    public function test_redemption_rechecks_every_revocation_and_identity_boundary(string $change): void
    {
        [$project, $source, $token] = $this->fixture();
        $code = $this->issue($project, $token);
        match ($change) {
            'membership' => $project->clients()->update(['active' => false]),
            'project' => $project->update(['status' => 'completed']),
            'source' => DB::table('integration_sources')->where('id', $source)->update(['sso_enabled' => false]),
            'token' => DB::table('integration_tokens')->where('uuid', $token['id'])->update(['revoked_at' => now()]),
            'grant' => DB::table('integration_source_projects')->where('integration_source_id', $source)->delete(),
            'email' => $project->clients()->update(['email' => 'new@example.test']),
        };
        $this->complete($code)->assertRedirectContains('reason=sso');
        $this->assertDatabaseCount('project_access_tokens', 0);
    }

    public function test_active_other_client_and_staff_sessions_are_preserved_and_not_switched(): void
    {
        [$project, , $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $other = Project::factory()->create(['client_email' => 'other@example.test']);
        $link = app(ClientInvitations::class)->issue($other, $other->clients()->first());
        $this->get('/access/'.basename($link['url']));
        $old = session('client_access_token_id');
        $this->complete($code)->assertRedirectContains('reason=sso');
        $this->assertSame($old, session('client_access_token_id'));
        $owner = User::factory()->owner($project->workspace)->create();
        $this->actingAs($owner);
        $this->complete($code)->assertRedirectContains('reason=sso');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_staff_email_cannot_get_a_client_handoff_and_origin_cannot_be_spoofed_by_another_browser_site(): void
    {
        [$project, , $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $this->post('/client-sso/enter', ['code' => $code], ['Origin' => 'https://evil.example.test'])->assertRedirectContains('reason=sso');
        $this->post('/client-sso/enter', ['code' => $code])->assertRedirectContains('reason=sso');
        User::factory()->memberOf($project->workspace)->create(['email' => 'ada@example.test']);
        $this->postJson(self::API, $this->input($project), $this->headers($token))->assertForbidden();
        $this->complete($code)->assertRedirectContains('reason=sso');
    }

    public function test_disabling_source_revokes_issued_codes_and_sso_sessions_but_keeps_magic_links(): void
    {
        [$project, $source, $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $this->complete($code);
        $access = ProjectAccessToken::first();
        $this->assertTrue($access->isUsable());
        $owner = User::factory()->owner($project->workspace)->create();
        $uuid = DB::table('integration_sources')->find($source)->uuid;
        $this->actingAs($owner)->patchJson('/api/admin/integrations/sources/'.$uuid.'/sso', ['ssoEnabled' => false, 'ssoOrigin' => self::ORIGIN, 'projectIds' => [$project->uuid]])->assertOk();
        $this->assertFalse($access->fresh()->isUsable());
        $this->patchJson('/api/admin/integrations/sources/'.$uuid.'/sso', ['ssoEnabled' => true, 'ssoOrigin' => self::ORIGIN, 'projectIds' => [$project->uuid]])->assertOk();
        $this->assertFalse($access->fresh()->isUsable());
        $magic = app(ClientInvitations::class)->issue($project, $project->clients()->first());
        $this->assertTrue(ProjectAccessToken::find($magic['id'])->isUsable());
    }

    public function test_sso_source_settings_are_owner_only_scoped_and_not_granted_to_old_tokens(): void
    {
        [$project, $source] = $this->fixture();
        $uuid = DB::table('integration_sources')->find($source)->uuid;
        $admin = User::factory()->memberOf($project->workspace)->create();
        $this->actingAs($admin)->patchJson('/api/admin/integrations/sources/'.$uuid.'/sso', ['ssoEnabled' => true, 'ssoOrigin' => self::ORIGIN, 'projectIds' => [$project->uuid]])->assertForbidden();
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        $foreign = Project::factory()->create();
        $this->patchJson('/api/admin/integrations/sources/'.$uuid.'/sso', ['ssoEnabled' => true, 'ssoOrigin' => self::ORIGIN, 'projectIds' => [$foreign->uuid]])->assertUnprocessable();
        $this->postJson('/api/admin/integrations/tokens', ['name' => 'SSO', 'scopes' => ['clients:sso']])->assertUnprocessable();
        $old = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Old', 'scopes' => ['projects:read']]);
        $this->assertSame(['projects:read'], json_decode(DB::table('integration_tokens')->where('uuid', $old['id'])->value('scopes'), true));
    }

    public function test_only_handoff_post_is_exempt_from_csrf_and_rejects_query_codes(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn () => new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        [$project, , $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $this->post('/client-sso/enter', ['code' => $code], ['Origin' => self::ORIGIN])->assertOk();
        $this->post('/client-sso/enter?code='.$code, [], ['Origin' => self::ORIGIN])->assertRedirectContains('reason=sso');
        $this->actingAs(User::factory()->owner($project->workspace)->create())->postJson('/api/admin/integrations/tokens', ['name' => 'No CSRF', 'scopes' => ['projects:read']])->assertStatus(419);
    }

    public function test_sso_token_expiry_and_scope_removal_revoke_existing_sso_access(): void
    {
        [$project, , $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $this->complete($code);
        $access = ProjectAccessToken::first();
        DB::table('integration_tokens')->where('uuid', $token['id'])->update(['scopes' => json_encode(['projects:read'])]);
        $this->assertFalse($access->fresh()->isUsable());
        DB::table('integration_tokens')->where('uuid', $token['id'])->update(['scopes' => json_encode(['clients:sso']), 'expires_at' => now()->subSecond()]);
        $this->assertFalse($access->fresh()->isUsable());
    }

    public function test_deleted_handoff_never_turns_sso_into_an_ordinary_magic_link(): void
    {
        [$project, , $token] = $this->fixture();
        $code = $this->issue($project, $token);
        $this->complete($code);
        $access = ProjectAccessToken::first();
        DB::table('client_sso_handoffs')->delete();
        $this->assertNotNull($access->fresh()->client_sso_handoff_id);
        $this->assertFalse($access->fresh()->isUsable());
        DB::table('client_sso_bindings')->delete();
        $migration = require database_path('migrations/2026_10_29_174316_add_client_sso_handoffs.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Keep SSO bindings and revocation markers');
        $migration->down();
    }

    public function test_disabled_clients_inactive_projects_ambiguous_email_and_unknown_fields_cannot_issue(): void
    {
        [$project, , $token] = $this->fixture();
        $this->postJson(self::API, [...$this->input($project), 'redirectUrl' => 'https://evil.example.test'], $this->headers($token))->assertUnprocessable();
        $this->postJson(self::API, [...$this->input($project), 'externalUserId' => str_repeat('a', 2100)], $this->headers($token))->assertStatus(413);
        $this->post(self::API, $this->input($project), [...$this->headers($token), 'Accept' => 'application/json'])->assertStatus(415);
        $project->clients()->update(['active' => false]);
        $this->postJson(self::API, $this->input($project), $this->headers($token))->assertForbidden();
        $project->clients()->update(['active' => true]);
        $project->update(['status' => 'completed']);
        $this->postJson(self::API, $this->input($project), $this->headers($token))->assertNotFound();
        $project->update(['status' => 'active']);
        $project->clients()->create(['name' => 'Duplicate identity', 'email' => 'ada@example.test']);
        $this->postJson(self::API, $this->input($project), $this->headers($token))->assertForbidden();
        $this->assertDatabaseCount('client_sso_bindings', 0);
    }

    public function test_issuance_reuses_existing_api_rate_limit_and_handoff_has_a_separate_limit(): void
    {
        [$project, , $token] = $this->fixture();
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit('integration-workspace:'.$project->workspace_id, 60);
        }
        $this->postJson(self::API, $this->input($project), $this->headers($token))->assertTooManyRequests();
        for ($i = 0; $i < 60; $i++) {
            $this->post('/client-sso/enter', ['code' => str_repeat('a', 64)])->assertRedirect();
        }
        $this->post('/client-sso/enter', ['code' => str_repeat('a', 64)])->assertTooManyRequests();
        $this->assertDatabaseCount('client_sso_handoffs', 0);
    }

    public function test_new_sources_are_disabled_and_rollback_preserves_binding_identity(): void
    {
        [$project, , $token] = $this->fixture();
        $this->issue($project, $token);
        $id = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'New']);
        $this->assertFalse((bool) DB::table('integration_sources')->find($id)->sso_enabled);
        $migration = require database_path('migrations/2026_10_29_174316_add_client_sso_handoffs.php');
        try {
            $migration->down();
            $this->fail('SSO binding identity was dropped');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Keep SSO bindings', $exception->getMessage());
        }
        $this->assertDatabaseCount('client_sso_bindings', 1);
    }

    private function provision(array $token, string $externalId = 'order-42'): Project
    {
        Mail::fake();
        $input = ['externalId' => $externalId, 'client' => ['name' => 'Ada', 'email' => 'ada@example.test'],
            'project' => ['name' => $externalId, 'description' => 'Website', 'language' => 'en'], 'sendInvitation' => false];
        $response = $this->postJson('/api/v1/projects/provision', $input, [...$this->headers($token), 'Idempotency-Key' => $externalId])->assertSuccessful();

        return Project::where('uuid', $response->json('data.projectId'))->firstOrFail();
    }

    public function test_provisioned_projects_are_automatic_with_separate_rotated_credentials_and_no_manual_grants(): void
    {
        [$legacy, $source, $sso] = $this->fixture();
        DB::table('integration_source_projects')->delete();
        $creator = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'Provision only', 'sourceId' => $source, 'scopes' => ['projects:provision']]);
        $first = $this->provision($creator);
        $this->assertSame($first->id, $this->provision($creator)->id);
        DB::table('integration_tokens')->where('uuid', $creator['id'])->update(['revoked_at' => now()]);
        DB::table('integration_tokens')->where('uuid', $sso['id'])->update(['revoked_at' => now()]);
        $sso = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'Replacement SSO', 'sourceId' => $source, 'scopes' => ['clients:sso']]);
        $creator = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'Replacement provision', 'sourceId' => $source, 'scopes' => ['projects:provision']]);
        $this->assertSame($first->id, $this->provision($creator)->id);
        $second = $this->provision($creator, 'order-43');
        foreach ([$first, $second] as $project) {
            $this->complete($this->issue($project, $sso))->assertRedirectContains('/project/'.$project->uuid);
            $this->getJson('/api/client/project/'.$project->uuid)->assertOk();
        }
        $this->assertDatabaseCount('integration_source_projects', 0);
        $this->assertDatabaseCount('client_sso_bindings', 1);
        $this->assertDatabaseCount('project_provisionings', 2);
        $this->postJson(self::API, $this->input($legacy), $this->headers($sso))->assertForbidden();
    }

    #[TestWith([false])] #[TestWith([true])]
    public function test_request_origin_not_approver_credentials_grants_sso_only_after_approval(bool $ownerApproval): void
    {
        Mail::fake();
        [$legacy, $source, $sso] = $this->fixture();
        DB::table('integration_source_projects')->delete();
        $submitter = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'Form', 'sourceId' => $source, 'scopes' => ['requests:create']]);
        $input = ['externalId' => 'request-42', 'client' => ['name' => 'Ada', 'email' => 'ada@example.test'], 'project' => ['name' => 'Requested', 'language' => 'en']];
        $id = $this->postJson('/api/v1/project-requests', $input, [...$this->headers($submitter), 'Idempotency-Key' => 'request-42'])->assertCreated()->json('data.requestId');
        $this->assertSame(0, app(ClientSso::class)->automaticProjects($source, $legacy->workspace_id)->count());
        DB::table('integration_tokens')->where('uuid', $submitter['id'])->update(['revoked_at' => now()]);
        $otherSource = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $legacy->workspace_id, 'name' => 'Other source', 'sso_enabled' => true, 'sso_origin' => self::ORIGIN]);
        $approver = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'CRM reviewer', 'sourceId' => $otherSource, 'scopes' => ['requests:approve', 'clients:sso']]);
        if ($ownerApproval) {
            $this->actingAs(User::factory()->owner($legacy->workspace)->create());
            $approved = $this->postJson('/api/admin/project-requests/'.$id.'/approve', ['sendInvitation' => false])->assertOk();
            $this->app['auth']->forgetGuards();
        } else {
            $approved = $this->postJson('/api/v1/project-requests/'.$id.'/approve', ['sendInvitation' => false], [...$this->headers($approver), 'Idempotency-Key' => 'approve-42'])->assertOk();
        }
        $project = Project::where('uuid', $approved->json('data.projectId'))->firstOrFail();
        $this->issue($project, $sso);
        $this->postJson(self::API, $this->input($project), $this->headers($approver))->assertForbidden();
        $this->assertDatabaseCount('integration_source_projects', 0);
        $this->assertDatabaseCount('project_provisionings', 1);
    }

    public function test_provenance_does_not_grant_other_sources_or_projects_without_a_source(): void
    {
        [$legacy, $source, $sso] = $this->fixture();
        DB::table('integration_source_projects')->delete();
        $otherSource = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $legacy->workspace_id, 'name' => 'Other source', 'sso_enabled' => true, 'sso_origin' => self::ORIGIN]);
        $creator = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'Another source', 'sourceId' => $otherSource, 'scopes' => ['projects:provision']]);
        $foreignSourceProject = $this->provision($creator);
        $noSource = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'Legacy token', 'scopes' => ['projects:provision']]);
        $unattributed = $this->provision($noSource);
        foreach ([$legacy, $foreignSourceProject, $unattributed, Project::factory()->create(['client_email' => 'ada@example.test'])] as $project) {
            $this->postJson(self::API, $this->input($project), $this->headers($sso))->assertStatus($project->workspace_id === $legacy->workspace_id ? 403 : 404);
        }
        DB::table('integration_source_projects')->insert(['workspace_id' => $legacy->workspace_id, 'integration_source_id' => $source, 'project_id' => $unattributed->id]);
        $this->issue($unattributed, $sso);
    }

    public function test_owner_can_enable_source_before_any_projects_and_automatic_permissions_are_read_only(): void
    {
        [$legacy, $source, $sso] = $this->fixture();
        DB::table('integration_source_projects')->delete();
        $owner = User::factory()->owner($legacy->workspace)->create();
        $this->actingAs($owner);
        $uuid = DB::table('integration_sources')->find($source)->uuid;
        $settings = ['ssoEnabled' => true, 'ssoOrigin' => self::ORIGIN, 'projectIds' => []];
        $this->patchJson('/api/admin/integrations/sources/'.$uuid.'/sso', $settings)->assertOk();
        $this->postJson(self::API, $this->input($legacy), $this->headers($sso))->assertForbidden();
        $creator = app(IntegrationTokens::class)->create($legacy->workspace_id, ['name' => 'Provision', 'sourceId' => $source, 'scopes' => ['projects:provision']]);
        $project = $this->provision($creator);
        $state = $this->getJson('/api/admin/integrations')->assertOk()->json('sources');
        $this->assertSame([$project->uuid], $state[0]['automaticSsoProjectIds']);
        $this->assertSame([], $state[0]['ssoProjectIds']);
        $this->patchJson('/api/admin/integrations/sources/'.$uuid.'/sso', $settings)->assertOk();
        $this->issue($project, $sso);
        $this->assertDatabaseCount('integration_source_projects', 0);
    }
}
