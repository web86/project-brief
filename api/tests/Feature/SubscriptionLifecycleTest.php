<?php

namespace Tests\Feature;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Plans\SubscriptionPeriod;
use App\Models\Plans\WorkspaceSubscription;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuthOnboarding;
use App\Services\IntegrationTokens;
use App\Services\Plans\ClientCountingIdentities;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\ExecutionBarrier;
use App\Services\Plans\FrozenTransitions;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlatformPolicyCatalog;
use App\Services\Plans\SubscriptionClock;
use App\Services\Plans\SubscriptionLifecycle;
use App\Services\ProjectAccess;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
            $restore = $previewService->create($owner, $workspace->id, false, $paidVersion->uuid, $restoreSelection, $subscription->fresh()->revision, 'restore-preview');
            $headers = ['If-Match' => '"revision:'.$subscription->fresh()->revision.'"', 'Idempotency-Key' => 'paid-restore'];
            $this->actingAs($owner)->postJson('/api/admin/resource-selection/previews/'.$restore['id'].'/restore-paid', [], $headers)->assertOk();
            $this->assertEqualsCanonicalizing([$project->id, $second->id], app(EffectiveParticipation::class)->projects($workspace->id)->pluck('id')->all());
            $this->assertSame(1, ProjectAccess::forMember($developer)->count());
            $this->assertFalse($barrier->allows($workspace->id, 'webhook'));

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
}
