<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\ExternalAgent;
use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Plans\SubscriptionPeriod;
use App\Models\Plans\WorkspaceSubscription;
use App\Models\Project;
use App\Models\TaskAgentAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\AgentRuns;
use App\Services\AuthOnboarding;
use App\Services\ClientSso;
use App\Services\DeveloperInvitations;
use App\Services\IntegrationTokens;
use App\Services\IntegrationWrites;
use App\Services\Plans\ClientCountingIdentities;
use App\Services\Plans\CommercialCanary;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\ExecutionBarrier;
use App\Services\Plans\FrozenTransitions;
use App\Services\Plans\InvitationOccupancy;
use App\Services\Plans\LegacyInventory;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlatformPolicyCatalog;
use App\Services\Plans\SubscriptionClock;
use App\Services\Plans\SubscriptionLifecycle;
use App\Services\ProjectAccess;
use App\Services\ProjectProvisioning;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use App\Services\WorkspaceBranding;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_primary_manual_assignments_preserve_trial_history_and_restore_selected_resources(): void
    {
        config(['onboarding.subject_retention_days' => 365]);
        $operator = User::factory()->owner()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $operator->id, 'revision' => 1]);
        $this->seed(PlansFoundationSeeder::class);
        $versions = [];
        foreach (['basic', 'freelancer', 'studio'] as $code) {
            $version = PlanVersion::whereHas('plan', fn ($query) => $query->where('code', $code))->firstOrFail();
            $versions[$code] = app(PlanCatalog::class)->publish($operator, $version->id, 1, 'manual-target-'.$code);
        }
        $policy = app(PlatformPolicyCatalog::class)->publish($operator, PlatformPolicyVersion::firstOrFail()->id, 1, 'manual-policy');
        DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
        $owner = User::factory()->create();
        app(AuthOnboarding::class)->pending($owner);
        $workspace = app(AuthOnboarding::class)->prepare($owner);
        $owner->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
            'recovery_codes_acknowledged_at' => now(),
        ])->save();
        $lifecycle = app(SubscriptionLifecycle::class);
        $trial = $lifecycle->startVerifiedTrial($workspace->id, $owner->id);
        $member = $owner->workspaceMembers()->firstOrFail();
        $developers = User::factory()->count(9)->memberOf($workspace)->create();
        $first = Project::factory()->create(['workspace_id' => $workspace->id]);
        $second = Project::factory()->create(['workspace_id' => $workspace->id]);
        $first->clients()->update(['active' => false]);
        $second->clients()->update(['active' => false]);
        $brand = ['visual' => ['companyName' => 'Synthetic Studio', 'primaryColor' => '#442288'],
            'business' => ['legalName' => 'Synthetic LLC'], 'export' => ['platform' => false, 'business' => true],
            'email' => ['enabled' => true]];
        $workspace->update(['branding' => $brand]);
        $branding = app(WorkspaceBranding::class);
        $this->assertSame('Synthetic Studio', $branding->email($workspace)['name']);
        $this->assertFalse($branding->document($workspace, 'en')['platform']);

        $invite = '/api/admin/administrators/invitations';
        $inviteBody = ['email' => 'extra@example.test', 'sendEmail' => false, 'generateLink' => true];
        $this->actingAs($owner)->postJson($invite, $inviteBody)->assertForbidden()
            ->assertJsonPath('code', 'plan_limit_reached')->assertJsonPath('planRestriction.entitlement', 'developers.max')
            ->assertJsonPath('planRestriction.usage', 10)->assertJsonPath('planRestriction.maximum', 10)
            ->assertJsonPath('planRestriction.attemptedUsage', 11)->assertJsonPath('planRestriction.plan', 'studio');
        $this->assertDatabaseMissing('developer_invitations', ['email' => $inviteBody['email']]);
        $this->postJson($invite, ['email' => 'invalid'])->assertUnprocessable()->assertJsonMissingPath('planRestriction');
        $this->actingAs($developers->first())->postJson($invite, $inviteBody)->assertForbidden()->assertJsonMissingPath('planRestriction');

        $root = '/api/platform/workspaces/'.$workspace->uuid.'/resource-selection';
        $ownerOnly = ['projects' => [$first->uuid], 'developers' => [(string) $member->id], 'clients' => [], 'agents' => [], 'invitations' => []];
        $this->actingAs($operator)->getJson($root.'/manual')->assertOk()->assertJsonCount(3, 'data.assignableVersions')->assertJsonPath('data.subscription.phase', 'trial');
        $freeze = function (string $code, array $selection) use ($root, $versions): array {
            $revision = $this->getJson($root.'/manual')->assertOk()->json('data.subscriptionRevision');
            $headers = ['If-Match' => '"revision:'.$revision.'"', 'Idempotency-Key' => (string) Str::uuid()];
            $preview = $this->postJson($root.'/manual/previews', ['targetVersionId' => $versions[$code]->uuid, 'selection' => $selection], $headers)
                ->assertCreated()->assertJsonPath('data.availableAction', 'assign-manual')->json('data');

            return [$root.'/previews/'.$preview['id'].'/assign-manual', $headers, $preview];
        };

        $endDate = CarbonImmutable::now('UTC')->addMonth()->toDateString();
        [$path, $headers, $preview] = $freeze('freelancer', $ownerOnly);
        $this->assertContains($second->uuid, $preview['wouldHold']['projects']);
        $this->assertSame(10, $preview['limits']['projects.active.max']['value']);
        $this->postJson($path, ['endDate' => $endDate, 'replaceActivePeriod' => false], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'manual_assignment_replacement_required');
        $this->travel(601)->seconds();
        $this->postJson($path, ['endDate' => $endDate, 'replaceActivePeriod' => true], $headers)
            ->assertStatus(423)->assertJsonPath('code', 'security_step_up_required');
        $this->actingAs($operator)->postJson($path, ['endDate' => $endDate, 'replaceActivePeriod' => true], $headers)->assertOk();
        $this->postJson($path, ['endDate' => $endDate, 'replaceActivePeriod' => true], $headers)->assertOk();
        $this->assertDatabaseHas('owner_trial_claims', ['workspace_id' => $workspace->id, 'period_id' => $trial->id, 'state' => 'used']);
        $this->assertSame($trial->id, $lifecycle->startVerifiedTrial($workspace->id, $owner->id)->id);
        $this->assertSame('Synthetic Studio', $branding->portal($workspace)['companyName']);
        $this->assertNull($branding->email($workspace));
        $this->assertSame('', $branding->document($workspace, 'en')['name']);
        $this->assertTrue($branding->document($workspace, 'en')['platform']);
        $this->actingAs($owner)->postJson($invite, $inviteBody)->assertForbidden()->assertJsonPath('planRestriction.maximum', 1);
        $brandingPath = '/api/admin/workspaces/'.$workspace->uuid.'/branding';
        $this->getJson($brandingPath)->assertOk()->assertJsonPath('data.level', 'basic');
        $this->patchJson($brandingPath, ['section' => 'business', 'values' => ['legalName' => 'Denied LLC']])
            ->assertForbidden()->assertJsonPath('code', 'plan_feature_unavailable');
        $this->patchJson($brandingPath, ['section' => 'visual', 'values' => ['companyName' => 'Freelancer brand']])->assertOk();

        $this->actingAs($operator);
        [$path, $headers] = $freeze('basic', $ownerOnly);
        $this->postJson($path, ['replaceActivePeriod' => true], $headers)->assertOk();
        $this->assertSame('', $branding->portal($workspace->fresh())['companyName']);
        $this->assertSame('Synthetic LLC', $workspace->fresh()->branding['business']['legalName']);
        $this->assertFalse(app(EffectiveParticipation::class)->projects($workspace->id)->where('projects.id', $second->id)->exists());
        $this->assertDatabaseHas('projects', ['id' => $second->id, 'status' => 'active']);
        $this->actingAs($owner)->getJson($brandingPath)->assertJsonPath('data.level', 'none');
        $this->patchJson($brandingPath, ['section' => 'visual', 'values' => ['companyName' => 'Denied']])
            ->assertForbidden()->assertJsonPath('code', 'plan_feature_unavailable');
        $this->postJson($invite, $inviteBody)->assertForbidden()->assertJsonPath('planRestriction.maximum', 1);
        $this->postJson('/api/admin/projects', ['title' => 'One project too many'])->assertForbidden()
            ->assertJsonPath('planRestriction.entitlement', 'projects.active.max')->assertJsonPath('planRestriction.usage', 1);

        $this->actingAs($operator);
        [$path, $headers] = $freeze('studio', array_replace($ownerOnly, ['projects' => [$first->uuid, $second->uuid]]));
        $result = $this->postJson($path, ['endDate' => $endDate, 'replaceActivePeriod' => false], $headers)->assertOk()->json('data');
        $this->assertSame(2, app(EffectiveParticipation::class)->projects($workspace->id)->count());
        $this->assertSame('Freelancer brand', $branding->email($workspace->fresh())['name']);
        $this->assertSame('Synthetic LLC', $branding->document($workspace->fresh(), 'en')['business'][0]['value']);
        $barrier = app(ExecutionBarrier::class);
        $this->assertFalse($barrier->allows($workspace->id, 'api'));
        $this->actingAs($owner)->postJson('/api/admin/resource-selection/rearm', ['groups' => ['api', 'agent', 'sso', 'webhook']], [
            'If-Match' => '"revision:'.$result['revision'].'"', 'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertTrue($barrier->allows($workspace->id, 'api'));
        $this->assertDatabaseCount('subscription_periods', 4);
        $this->assertDatabaseCount('subscription_changes', 3);
        $this->assertDatabaseHas('plan_events', ['actor_user_id' => $operator->id, 'action' => 'subscription.assign_manual']);
    }

    public static function kinds(): array
    {
        return ['trial' => ['trial', 1], 'paid' => ['paid', 2]];
    }

    #[DataProvider('kinds')]
    public function test_exact_expiry_is_durable_idempotent_and_holds_resources_without_mutating_them(string $kind, int $transitionCount): void
    {
        $verified = CarbonImmutable::parse('2026-10-24T22:10:11.123456Z');
        $this->travelTo($verified);
        $actor = User::factory()->owner()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $actor->id, 'revision' => 1]);
        $this->seed(PlansFoundationSeeder::class);
        foreach (['basic', 'studio'] as $code) {
            $draft = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', $code))->firstOrFail();
            app(PlanCatalog::class)->publish($actor, $draft->id, 1, 'target-'.$code);
        }
        $policy = app(PlatformPolicyCatalog::class)->publish($actor, PlatformPolicyVersion::firstOrFail()->id, 1, 'policy');
        DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
        $facts = app(SubscriptionClock::class)->period($kind, $verified, 7, 7);
        $lifecycle = app(SubscriptionLifecycle::class);
        if ($kind === 'trial') {
            config(['onboarding.subject_retention_days' => 365]);
            $owner = User::factory()->create();
            app(AuthOnboarding::class)->pending($owner);
            $workspace = app(AuthOnboarding::class)->prepare($owner);
            $owner->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
                'two_factor_confirmed_at' => now(),
                'recovery_codes_acknowledged_at' => now(),
            ])->save();
            $this->assertDatabaseCount('subscription_periods', 0);
            $period = $lifecycle->startVerifiedTrial($workspace->id, $owner->id);
            $this->assertSame($period->id, $lifecycle->startVerifiedTrial($workspace->id, $owner->id)->id);
            $this->assertTrue($verified->equalTo($period->starts_at));
            $this->assertTrue($verified->addSeconds(604800)->equalTo($period->service_ends_at));
            $this->assertNull($period->grace_ends_at);
            $subscription = WorkspaceSubscription::findOrFail($period->subscription_id);
        } else {
            $workspace = Workspace::factory()->create();
            $owner = User::factory()->owner($workspace)->create();
            $subscription = WorkspaceSubscription::create(['workspace_id' => $workspace->id, 'materialized_phase' => $kind, 'enforcement_mode' => 'enforce', 'revision' => 1, 'next_boundary_at' => $facts['service_ends_at']]);
            $period = SubscriptionPeriod::create($facts + ['workspace_id' => $workspace->id, 'subscription_id' => $subscription->id, 'entitlement_version_id' => $policy->trial_version_id, 'policy_version_id' => $policy->id, 'fallback_version_id' => $policy->fallback_version_id, 'approval_kind' => 'manual']);
            $subscription->update(['current_period_id' => $period->id]);
        }
        $projects = Project::factory()->count(100)->sequence(fn ($sequence) => ['client_email' => 'client-'.$sequence->index.'@example.test'])->create(['workspace_id' => $workspace->id, 'status' => 'active']);
        $developer = User::factory()->memberOf($workspace)->create()->workspaceMembers()->firstOrFail();
        $projects->first()->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $developer->id]);
        $this->assertSame(1, ProjectAccess::forMember($developer)->count());
        $boundary = $facts['grace_ends_at'] ?? $facts['service_ends_at'];
        $agent = ExternalAgent::factory()->create(['workspace_id' => $workspace->id]);
        $first = $projects->first();
        $first->members()->create(['workspace_id' => $workspace->id, 'external_agent_id' => $agent->id]);
        DB::table('agent_project_grants')->insert(['workspace_id' => $workspace->id, 'external_agent_id' => $agent->id, 'project_id' => $first->id]);
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $workspace->id, 'invited_by' => $owner->id, 'project_ids' => [$first->id]]);
        app(DeveloperInvitations::class)->issue($invitation);
        // Keep the invitation valid just after Trial expiry to exercise restoring an existing reservation.
        $invitation->forceFill(['expires_at' => $boundary->addDay()])->save();
        app(InvitationOccupancy::class)->sync($invitation->id);
        $endpoint = DB::table('webhook_endpoints')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Independent retry',
            'url' => 'https://example.test/events',
            'events' => '[]',
            'secret' => 'unused-synthetic-fixture',
        ]);
        $this->assertNotNull(app(WebhookEvents::class)->enqueue($workspace->id, 'webhook.test', [], $endpoint));
        DB::table('webhook_deliveries')->update(['attempts' => 1]);

        $token = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'Boundary test', 'scopes' => ['projects:read', 'tasks:write']]);
        $this->travelTo($facts['service_ends_at']->subMicrosecond());
        $this->withToken($token['secret'])->getJson('/api/v1/projects')->assertOk();
        $taskPath = '/api/v1/projects/'.$projects->first()->uuid.'/tasks';
        $taskPayload = ['title' => 'Preserved task', 'location' => 'Общее', 'description' => 'Synthetic fixture', 'priority' => 'normal'];
        $taskHeaders = ['Idempotency-Key' => 'same-task-request'];
        $this->postJson($taskPath, $taskPayload, $taskHeaders)->assertCreated();
        $this->postJson($taskPath, $taskPayload, $taskHeaders)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $task = $projects->first()->tasks()->firstOrFail();
        $assignment = TaskAgentAssignment::create(['workspace_id' => $workspace->id, 'task_id' => $task->id, 'external_agent_id' => $agent->id]);
        $run = DB::transaction(function () use ($assignment): object {
            IntegrationWrites::lockWorkspace($assignment->workspace_id);

            return AgentRuns::request(Request::create('/'), $assignment, 'Old instruction', 'old-run', false);
        });
        $shared = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'Shared executor', 'shared' => true, 'scopes' => AgentAccess::SCOPES]);
        $sharedId = DB::table('integration_tokens')->where('uuid', $shared['id'])->value('id');
        DB::table('agent_credential_links')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'integration_token_id' => $sharedId, 'external_agent_id' => $agent->id, 'created_at' => now()]);
        $agentPath = '/api/v1/agent/tasks/'.$task->uuid.'/runs/'.$run->uuid;
        $agentHeaders = ['Authorization' => 'Bearer '.$shared['secret'], 'X-ProjectBrief-Agent-ID' => $agent->uuid];
        $this->getJson($agentPath, $agentHeaders)->assertOk();
        $origin = 'https://studio.example.test';
        $source = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Synthetic SSO', 'sso_enabled' => true, 'sso_origin' => $origin]);
        DB::table('integration_source_projects')->insert(['workspace_id' => $workspace->id, 'integration_source_id' => $source, 'project_id' => $first->id]);
        $ssoToken = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'SSO integration', 'scopes' => ['clients:sso'], 'sourceId' => $source]);
        $ssoPath = '/api/v1/client-sso/handoffs';
        $ssoHeaders = ['Authorization' => 'Bearer '.$ssoToken['secret']];
        $ssoPayload = ['projectId' => $first->uuid, 'externalUserId' => 'synthetic-sso-id', 'email' => 'client-0@example.test', 'emailVerified' => true];
        $oldCode = $this->postJson($ssoPath, $ssoPayload, $ssoHeaders)->assertCreated()->json('data.code');
        $this->assertSame($kind, $subscription->fresh()->materialized_phase);
        $this->assertDatabaseCount('workspace_suspension_cycles', 0);

        $this->travelTo($boundary);
        $this->withToken($token['secret'])->getJson('/api/v1/projects')->assertForbidden();
        $this->postJson($taskPath, $taskPayload, $taskHeaders)->assertForbidden()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertSame(1, $projects->first()->tasks()->count());
        $this->travelTo($boundary->addMicrosecond());
        $lifecycle->catchUp($workspace->id);
        $subscription->refresh();
        $this->assertSame('free', $subscription->materialized_phase);
        $this->assertSame(1 + $transitionCount, $subscription->revision);
        $this->assertTrue($boundary->equalTo($subscription->currentPeriod->starts_at));
        $this->assertEquals($policy->fallback_version_id, $subscription->currentPeriod->entitlement_version_id);
        $this->assertDatabaseCount('subscription_periods', 2);
        $this->assertDatabaseCount('workspace_suspension_cycles', 1);
        $this->assertDatabaseCount('workspace_entitlement_resource_states', 0);
        $this->assertDatabaseCount('workspace_execution_epochs', 4);
        $this->assertDatabaseCount('plan_notification_outbox', $transitionCount + ($kind === 'trial' ? 1 : 0));
        $this->assertSame(0, app(EffectiveParticipation::class)->projects($workspace->id)->count());
        $this->assertSame(100, Project::where('workspace_id', $workspace->id)->where('status', 'active')->count());
        $this->assertSame(0, ProjectAccess::forMember($developer)->count());
        $this->assertSame('active', $developer->fresh()->status);
        $this->assertSame(100, ProjectAccess::forMember($owner->workspaceMembers()->firstOrFail())->count());

        Http::fake();
        $this->assertSame(1, app(WebhookProcessor::class)->process());
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_endpoint_id' => $endpoint, 'attempts' => 1, 'status' => 'cancelled', 'error_code' => 'subscription_execution_blocked']);
        Http::assertNothingSent();

        if ($kind === 'paid') {
            DB::beginTransaction();
            try {
                IntegrationWrites::lockWorkspace($workspace->id);
                $credential = DB::table('integration_tokens')->where('uuid', $token['id'])->first();
                [$provisioned, $status] = app(ProjectProvisioning::class)->create($credential, [
                    'externalId' => 'capacity-audit',
                    'client' => ['name' => 'Provisioned client', 'email' => 'provisioned@example.test', 'company' => null, 'website' => null],
                    'project' => ['name' => 'Provisioned project', 'description' => 'Synthetic', 'language' => 'en'],
                    'sendInvitation' => false,
                ]);
                $this->assertSame(201, $status);
                $participation = DB::table('project_clients')->where('uuid', $provisioned['data']['projectClientId'])->first();
                $this->assertNotNull($participation->entitlement_identity_id);
                $this->assertDatabaseHas('entitlement_client_observations', ['project_client_id' => $participation->id, 'state' => 'ready']);
            } finally {
                DB::rollBack();
            }
            $created = $this->actingAs($owner)->postJson('/api/admin/projects', ['title' => 'First effective project'])->assertCreated()->json('data.id');
            $this->postJson('/api/admin/projects', ['title' => 'Over capacity'])->assertForbidden();
            $route = '/api/admin/projects/'.$created.'/clients';
            foreach (range(1, 3) as $index) {
                $this->postJson($route, ['name' => 'Client '.$index, 'email' => 'basic-'.$index.'@example.test'])->assertCreated();
            }
            $this->postJson($route, ['name' => 'Client duplicate', 'email' => 'basic-1@example.test'])->assertOk();
            $this->postJson($route, ['name' => 'Fourth client', 'email' => 'basic-4@example.test'])->assertForbidden();
            $this->assertSame(1, app(EffectiveParticipation::class)->projects($workspace->id)->count());
            $this->assertSame(3, Project::where('uuid', $created)->firstOrFail()->clients()->count());
            $this->postJson('/api/admin/administrators/invitations', [
                'email' => 'blocked-developer@example.test', 'sendEmail' => false, 'generateLink' => false,
            ])->assertForbidden();
            $this->assertDatabaseMissing('developer_invitations', ['email' => 'blocked-developer@example.test']);
            $replacement = $projects->first();
            DB::transaction(fn () => app(ClientCountingIdentities::class)->reconcile($workspace->id, 0, (int) DB::table('project_clients')->max('id')));
            $preview = app(FrozenTransitions::class)->create($owner, $workspace->id, false, $subscription->currentPeriod->version->uuid, [
                'projects' => [$replacement->uuid],
                'developers' => [(string) $owner->workspaceMembers()->firstOrFail()->id],
                'clients' => [], 'agents' => [], 'invitations' => [],
            ], $subscription->revision, 'switch-created-preview');
            app(FrozenTransitions::class)->applySelection($owner, $workspace->id, $preview['id'], $subscription->revision, 'switch-created');
            $this->assertSame([$replacement->id], app(EffectiveParticipation::class)->projects($workspace->id)->pluck('id')->all());
            $this->postJson('/api/admin/projects/'.$created.'/team/invitations', ['email' => $developer->user->email])->assertForbidden();
            $this->assertDatabaseMissing('project_members', ['project_id' => Project::where('uuid', $created)->value('id'), 'workspace_member_id' => $developer->id]);
        }

        $barrier = app(ExecutionBarrier::class);
        $subscription->update(['enforcement_mode' => 'observe']);
        $this->assertFalse($barrier->allows($workspace->id, 'agent'));
        $this->assertFalse($barrier->allows($workspace->id, 'webhook'));
        // Simulate a later confirmed epoch. A historic receipt remains stale.
        DB::table('workspace_execution_epochs')->where('workspace_id', $workspace->id)->where('feature_group', 'agent')->update(['state' => 'ready', 'epoch' => 2]);
        $this->assertFalse($barrier->allows($workspace->id, 'agent', (object) ['execution_epoch' => null]));
        $this->assertFalse($barrier->allows($workspace->id, 'agent', (object) ['execution_epoch' => 1, 'execution_feature_group' => 'agent']));
        $this->assertFalse($barrier->allows($workspace->id, 'agent', (object) ['execution_epoch' => 2, 'execution_feature_group' => 'agent']));

        if ($kind === 'trial') {
            $project = $projects->first();
            DB::transaction(fn () => app(ClientCountingIdentities::class)->reconcile($workspace->id, 0, (int) DB::table('project_clients')->max('id')));
            $client = $project->clients()->firstOrFail();
            $identity = DB::table('entitlement_client_identities')->where('id', $client->entitlement_identity_id)->value('uuid');
            $previewService = app(FrozenTransitions::class);
            $version = $subscription->currentPeriod->version;
            $revision = $subscription->revision;
            $preview = $previewService->create($owner, $workspace->id, false, $version->uuid, [
                'projects' => [$project->uuid],
                'developers' => [(string) $owner->workspaceMembers()->firstOrFail()->id],
                'clients' => [$identity],
                'agents' => [],
                'invitations' => [],
            ], $revision, 'selection-preview');
            $this->assertSame([], array_values(array_diff($preview['reasonCodes'], ['subscription_missing_or_incomplete', 'participation_cycle_adapter_required'])));
            $headers = ['If-Match' => '"revision:'.$revision.'"', 'Idempotency-Key' => 'basic-selection'];
            $path = '/api/admin/resource-selection/previews/'.$preview['id'].'/apply-basic';
            $this->actingAs($owner)->postJson($path, [], $headers)->assertOk()->assertJsonPath('data.subscriptionRevision', (string) ($revision + 1));
            $this->postJson($path, [], $headers)->assertOk()->assertJsonPath('data.subscriptionRevision', (string) ($revision + 1));
            $this->assertSame([$project->id], app(EffectiveParticipation::class)->projects($workspace->id)->pluck('id')->all());
            $this->assertDatabaseCount('workspace_entitlement_resource_states', 2);
            $this->assertDatabaseHas('workspace_entitlement_resource_states', ['resource_kind' => 'project_client', 'resource_id' => $client->id, 'state' => 'released']);
            $this->assertFalse($barrier->allows($workspace->id, 'webhook'));

            $subscription->refresh()->update(['enforcement_mode' => 'enforce']);
            $paidVersion = PlanVersion::findOrFail($policy->trial_version_id);
            $approval = $previewService->create($actor, $workspace->id, true, $paidVersion->uuid, $preview['selection'], $subscription->revision, 'paid-preview');
            $headers = ['If-Match' => '"revision:'.$subscription->revision.'"', 'Idempotency-Key' => 'paid-approval'];
            $path = '/api/platform/workspaces/'.$workspace->uuid.'/resource-selection/previews/'.$approval['id'].'/approve-paid';
            $payload = ['serviceDays' => 30, 'reason' => 'synthetic_manual_approval'];
            $this->postJson($path, $payload, $headers)->assertForbidden();
            $this->actingAs($actor)->postJson($path, $payload, $headers)->assertOk();
            $this->postJson($path, $payload, $headers)->assertOk();
            $this->assertSame('paid', $subscription->fresh()->materialized_phase);
            $this->assertDatabaseCount('subscription_periods', 3);
            $this->assertDatabaseCount('workspace_suspension_cycles', 1);
            $this->assertFalse($barrier->allows($workspace->id, 'webhook'));

            $second = $projects[1];
            $restoreSelection = $preview['selection'];
            $restoreSelection['projects'][] = $second->uuid;
            $restoreSelection['developers'][] = (string) $developer->id;
            $restoreSelection['agents'][] = $agent->uuid;
            $restoreSelection['invitations'][] = $invitation->uuid;
            $restore = $previewService->create($owner, $workspace->id, false, $paidVersion->uuid, $restoreSelection, $subscription->fresh()->revision, 'restore-preview');
            $headers = ['If-Match' => '"revision:'.$subscription->fresh()->revision.'"', 'Idempotency-Key' => 'paid-restore'];
            $this->actingAs($owner)->postJson('/api/admin/resource-selection/previews/'.$restore['id'].'/restore-paid', [], $headers)->assertOk();
            $this->assertEqualsCanonicalizing([$project->id, $second->id], app(EffectiveParticipation::class)->projects($workspace->id)->pluck('id')->all());
            $this->assertSame(1, ProjectAccess::forMember($developer)->count());
            $this->assertFalse($barrier->allows($workspace->id, 'webhook'));
            $this->assertDatabaseHas('workspace_entitlement_resource_states', ['resource_kind' => 'external_agent', 'resource_id' => $agent->id, 'state' => 'released']);
            $cycle = DB::table('workspace_suspension_cycles')->first();
            $this->assertDatabaseHas('entitlement_capacity_reservations', ['developer_invitation_id' => $invitation->id, 'restored_cycle_id' => $cycle->id]);
            $rearmRevision = $subscription->fresh()->revision;
            $rearmHeaders = ['If-Match' => '"revision:'.$rearmRevision.'"', 'Idempotency-Key' => 'explicit-rearm'];
            $this->postJson('/api/admin/resource-selection/rearm', ['groups' => ['agent', 'api', 'sso', 'webhook']], $rearmHeaders)->assertOk();
            $this->assertTrue($barrier->allows($workspace->id, 'webhook'));
            $sso = app(ClientSso::class);
            $oldHandoff = $sso->handoff($oldCode);
            $this->assertTrue(CarbonImmutable::parse($oldHandoff->expires_at, 'UTC')->isFuture());
            $this->assertFalse($sso->acceptsOrigin($oldHandoff, $origin));
            $freshCode = $this->postJson($ssoPath, $ssoPayload, $ssoHeaders)->assertCreated()->json('data.code');
            $this->assertTrue($sso->acceptsOrigin($sso->handoff($freshCode), $origin));
            $this->withToken($token['secret'])->getJson('/api/v1/projects')->assertOk()->assertJsonCount(2, 'data');
            $this->getJson('/api/v1/projects/'.$projects[2]->uuid)->assertNotFound();
            $this->postJson($taskPath, $taskPayload, $taskHeaders)->assertConflict()->assertHeaderMissing('Idempotency-Replayed');
            $this->getJson($agentPath, $agentHeaders)->assertConflict()->assertDontSee('Old instruction');
            $this->postJson($agentPath.'/start', ['assignmentId' => $assignment->uuid, 'runId' => $run->uuid], $agentHeaders + ['Idempotency-Key' => 'stale-claim'])->assertConflict();
            $this->artisan('plans:reconcile-subscriptions', ['--apply' => true, '--limit' => 1, '--jobs' => 1])->assertSuccessful();
            $this->assertDatabaseHas('agent_runs', ['id' => $run->id, 'state' => 'cancelled', 'error_code' => 'subscription_execution_stale']);
            DB::table('webhook_deliveries')->update(['status' => 'failed']);
            $endpointUuid = DB::table('webhook_endpoints')->where('id', $endpoint)->value('uuid');
            $deliveryUuid = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint)->value('uuid');
            $this->postJson('/api/admin/integrations/webhooks/'.$endpointUuid.'/deliveries/'.$deliveryUuid.'/retry')->assertConflict();
            $this->assertDatabaseHas('webhook_deliveries', ['uuid' => $deliveryUuid, 'status' => 'failed']);
            DB::table('webhook_deliveries')->update(['status' => 'pending']);
            $this->assertSame(1, app(WebhookProcessor::class)->process());
            Http::assertNothingSent();

            $current = $subscription->fresh()->currentPeriod;
            $all = $previewService->options($actor, $workspace->id, true)['resources'];
            $retain = array_map(fn (array $rows): array => array_column($rows, 'id'), $all);
            $revision = $subscription->fresh()->revision;
            $renew = $previewService->create($actor, $workspace->id, true, $paidVersion->uuid, $retain, $revision, 'renewal-preview');
            $headers = ['If-Match' => '"revision:'.$revision.'"', 'Idempotency-Key' => 'paid-renewal'];
            $path = '/api/platform/workspaces/'.$workspace->uuid.'/resource-selection/previews/'.$renew['id'].'/approve-paid';
            $this->actingAs($actor)->postJson($path, $payload, $headers)->assertOk();
            $this->assertSame($current->id, $subscription->fresh()->current_period_id);
            $this->assertDatabaseHas('subscription_changes', ['kind' => 'renew', 'state' => 'scheduled']);
            $this->travelTo($current->service_ends_at);
            $lifecycle->catchUp($workspace->id);
            $this->assertSame('paid', $subscription->fresh()->materialized_phase);
            $this->assertTrue($current->service_ends_at->equalTo($subscription->fresh()->currentPeriod->starts_at));
            $this->assertDatabaseCount('workspace_suspension_cycles', 1);
            $this->assertDatabaseHas('subscription_changes', ['kind' => 'renew', 'state' => 'applied']);
        }
    }

    public function test_canary_activates_atomically_and_preserves_trials_when_stopped(): void
    {
        $this->freezeSecond();
        config([
            'onboarding.subject_retention_days' => 365,
            'onboarding.trial_activation_requested' => false,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'synthetic.example.test',
            'mail.from.address' => 'sender@example.test',
        ]);
        $primary = User::factory()->owner()->create(['platform_admin' => true]);
        $secondary = User::factory()->owner()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $primary->id, 'revision' => 1]);
        $this->seed(PlansFoundationSeeder::class);
        foreach (['basic', 'studio'] as $code) {
            $draft = PlanVersion::whereHas('plan', fn ($query) => $query->where('code', $code))->firstOrFail();
            app(PlanCatalog::class)->publish($primary, $draft->id, 1, 'canary-'.$code);
        }
        $policy = app(PlatformPolicyCatalog::class)->publish($primary, PlatformPolicyVersion::firstOrFail()->id, 1, 'canary-policy');
        DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
        $inventory = app(LegacyInventory::class);
        $inventory->bootstrap();
        foreach ([$primary, $secondary] as $operator) {
            $inventory->process($operator->workspaceMembers()->firstOrFail()->workspace_id);
        }
        $claimIds = [];
        $ownerEmails = [];
        foreach (range(1, 11) as $index) {
            $owner = User::factory()->create();
            app(AuthOnboarding::class)->pending($owner);
            app(AuthOnboarding::class)->prepare($owner);
            $owner->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
                'two_factor_confirmed_at' => now(),
                'recovery_codes_acknowledged_at' => now(),
            ])->save();
            $claimIds[] = DB::table('owner_trial_claims')->where('owner_user_id', $owner->id)->value('uuid');
            $ownerEmails[] = $owner->email;
        }
        $revision = (int) DB::table('plan_settings')->where('id', 1)->value('revision');
        $headers = fn (int $at, string $key): array => ['If-Match' => '"revision:'.$at.'"', 'Idempotency-Key' => $key];
        $endpoint = '/api/platform/registration-canary/';
        $payload = ['claimId' => $claimIds[0]];
        $this->actingAs($secondary)->getJson($endpoint.'owners')->assertForbidden()->assertDontSee($ownerEmails[0]);
        $this->postJson($endpoint.'admit', $payload, $headers($revision, 'secondary'))->assertForbidden();
        $this->actingAs($primary)->postJson($endpoint.'admit', $payload, $headers($revision, 'disabled'))
            ->assertForbidden()->assertJsonPath('code', 'canary_disabled');
        $this->getJson($endpoint.'owners')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('data.management.revision', (string) $revision)
            ->assertJsonPath('data.management.claimLimit', 10)
            ->assertJsonPath('data.management.windowHours', 24)
            ->assertJsonPath('data.management.owners.10.email', $ownerEmails[0])
            ->assertJsonPath('data.management.owners.10.eligible', true)
            ->assertJsonPath('data.canary.admissionEnabled', false)
            ->assertDontSee('two_factor')->assertDontSee('content_hash');
        $this->getJson($endpoint.'owners?page=2')->assertOk()->assertJsonCount(0, 'data.management.owners');
        $this->getJson('/api/platform/registration-diagnostics')->assertOk()->assertDontSee($claimIds[0]);

        config(['onboarding.trial_activation_requested' => true]);
        $this->travel(601)->seconds();
        $this->postJson($endpoint.'admit', $payload, $headers($revision, 'stale-mfa'))
            ->assertStatus(423)->assertJsonPath('code', 'security_step_up_required');
        $this->actingAs($primary)->postJson($endpoint.'admit', $payload, $headers($revision - 1, 'stale'))
            ->assertConflict()->assertJsonPath('code', 'plan_revision_conflict');
        $this->assertSame(0, SubscriptionPeriod::where('kind', 'trial')->count());
        $this->assertSame(0, DB::table('plan_events')->where('action', 'canary.admit')->count());
        $this->postJson($endpoint.'admit', $payload, $headers($revision, 'admission'))
            ->assertOk()->assertJsonPath('data.revision', (string) ($revision + 1));
        $this->postJson($endpoint.'admit', $payload, $headers($revision, 'admission'))->assertOk();
        $revision++;
        $this->postJson($endpoint.'admit', $payload, $headers($revision, 'new-key'))->assertConflict()
            ->assertJsonPath('code', 'canary_claim_unavailable');
        $this->getJson($endpoint.'owners')->assertOk()
            ->assertJsonPath('data.management.admittedOwners', 1)
            ->assertJsonPath('data.management.owners.10.state', 'active');

        $this->postJson($endpoint.'stop', [], $headers($revision++, 'stop'))->assertOk();
        $this->getJson('/api/platform/registration-diagnostics')->assertOk()->assertJsonPath('data.canary.activationStopped', true);
        $this->postJson($endpoint.'admit', ['claimId' => $claimIds[1]], $headers($revision, 'stopped'))
            ->assertConflict()->assertJsonPath('code', 'canary_stopped');
        $this->assertSame(1, SubscriptionPeriod::where('kind', 'trial')->count());
        config(['onboarding.trial_activation_requested' => false]);
        $this->postJson($endpoint.'resume', [], $headers($revision, 'resume-disabled'))->assertForbidden();
        config(['onboarding.trial_activation_requested' => true]);
        $this->postJson($endpoint.'resume', [], $headers($revision++, 'resume'))->assertOk();
        $service = app(CommercialCanary::class);
        foreach (array_slice($claimIds, 1, 9) as $index => $claimId) {
            $service->admit($primary, $revision++, $claimId, 'quota-'.$index);
        }
        $this->postJson($endpoint.'admit', ['claimId' => $claimIds[10]], $headers($revision, 'quota-full'))
            ->assertConflict()->assertJsonPath('code', 'canary_limit_reached');
        $this->assertSame(10, SubscriptionPeriod::where('kind', 'trial')->count());
        $this->assertSame(0, DB::table('plan_events')->where('action', 'canary.approve')->count());
        $this->assertSame(10, DB::table('plan_events')->where('action', 'canary.admit')->count());
        $this->travel(24)->hours();
        $this->actingAs($primary)->postJson($endpoint.'admit', ['claimId' => $claimIds[10]], $headers($revision, 'quota-reset'))->assertOk();
        $this->assertSame(11, SubscriptionPeriod::where('kind', 'trial')->count());
        $this->assertSame(2, WorkspaceSubscription::where('enforcement_mode', 'observe')->count());
    }
}
