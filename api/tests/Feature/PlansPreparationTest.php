<?php

namespace Tests\Feature;

use App\Models\DeveloperInvitation;
use App\Models\ExternalAgent;
use App\Models\Plans\PlanVersion;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Plans\AccountingReview;
use App\Services\Plans\FrozenTransitions;
use App\Services\Plans\LegacyInventory;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\ShadowGuard;
use App\Services\Plans\SubscriptionAssignments;
use App\Services\Plans\WorkspaceUsage;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlansPreparationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupWorkspace(): array
    {
        $project = Project::factory()->create(['status' => 'active']);
        $project->clients()->delete();
        ProjectClient::factory()->create(['project_id' => $project->id, 'email' => 'one@example.test']);
        $owner = $this->ownerFor($project, ['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $owner->id, 'revision' => 1]);
        $this->seed(PlansFoundationSeeder::class);
        $basic = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', 'basic'))->firstOrFail();
        $basic = app(PlanCatalog::class)->publish($owner, $basic->id, 1, 'basic');
        app(LegacyInventory::class)->bootstrap();
        app(LegacyInventory::class)->process($project->workspace_id);

        return [$project, $owner, $basic];
    }

    private function selection(array $options): array
    {
        return ['projects' => [$options['resources']['projects'][0]['id']], 'developers' => array_column($options['resources']['developers'], 'id'), 'clients' => array_column($options['resources']['clients'], 'id'), 'agents' => [], 'invitations' => []];
    }

    public function test_frozen_selection_is_idempotent_bound_to_actor_and_detects_resource_drift_without_applying(): void
    {
        [$p, $owner, $basic] = $this->setupWorkspace();
        $service = app(FrozenTransitions::class);
        $options = $service->options($owner, $p->workspace_id, false);
        $selection = $this->selection($options);
        $preview = $service->create($owner, $p->workspace_id, false, $basic->uuid, $selection, 1, 'one');
        $this->assertSame('ready', $preview['state']);
        $this->assertFalse($preview['executorAvailable']);
        $this->assertFalse($preview['executionContract']['executable']);
        $this->assertSame($preview['selectionHash'], $preview['executionContract']['selectionHash']);
        $receipts = array_column(array_merge(...$preview['executionContract']['batches']), 'receiptKey');
        $this->assertCount(count(array_unique($receipts)), $receipts);
        $this->assertSame($preview['id'], $service->create($owner, $p->workspace_id, false, $basic->uuid, $selection, 1, 'one')['id']);
        $this->assertTrue($service->read($owner, $p->workspace_id, false, $preview['id'])['verified']);
        $this->assertDatabaseCount('workspace_suspension_cycles', 0);
        $this->assertDatabaseCount('workspace_execution_epochs', 0);
        $this->assertDatabaseCount('subscription_periods', 1);
        $p->update(['status' => 'inactive']);
        try {
            $service->read($owner, $p->workspace_id, false, $preview['id']);
            $this->fail('Stale selection accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_owner_cannot_be_removed_and_expired_preview_is_not_a_permit(): void
    {
        [$p, $owner, $basic] = $this->setupWorkspace();
        $service = app(FrozenTransitions::class);
        $options = $service->options($owner, $p->workspace_id, false);
        $selection = $this->selection($options);
        $preview = $service->create($owner, $p->workspace_id, false, $basic->uuid, $selection, 1, 'ttl');
        $selection['developers'] = [];
        try {
            $service->create($owner, $p->workspace_id, false, $basic->uuid, $selection, 1, 'bad');
            $this->fail('Owner removed');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->travel(901)->seconds();
        try {
            $service->read($owner, $p->workspace_id, false, $preview['id']);
            $this->fail('Expired preview accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_shadow_pinned_compatibility_missing_subscription_and_safe_reduce_are_non_enforcing(): void
    {
        [$p] = $this->setupWorkspace();
        $guard = app(ShadowGuard::class);
        $result = $guard->observe($p->workspace_id, 'project.create', ['projects.active.max' => 100]);
        $this->assertSame('would_allow', collect($result['decisions'])->firstWhere('key', 'projects.active.max')['state']);
        $this->assertFalse($result['enforced']);
        $other = Workspace::factory()->create();
        $this->assertSame('unknown', $guard->observe($other->id, 'api.read')['decisions'][0]['state']);
        $this->assertSame([], $guard->observe($other->id, 'safe.reduce')['decisions']);
    }

    public function test_sensitive_http_boundary_no_mutation_routes_and_metadata_only_platform_options(): void
    {
        [$p, $owner] = $this->setupWorkspace();
        $this->actingAs($owner)->getJson('/api/admin/resource-selection')->assertOk()->assertJsonPath('data.previewOnly', true);
        $this->getJson('/api/platform/workspaces/'.$p->workspace->uuid.'/resource-selection')->assertOk()->assertDontSee($p->title);
        $other = User::factory()->owner()->create(['platform_admin' => true]);
        $this->actingAs($other)->getJson('/api/platform/workspaces/'.$p->workspace->uuid.'/resource-selection')->assertForbidden();
        $this->postJson('/api/admin/resource-selection/apply')->assertNotFound();
    }

    public function test_accounting_split_is_revision_bound_idempotent_and_never_changes_access(): void
    {
        [$p,$owner] = $this->setupWorkspace();
        $one = ProjectClient::where('project_id', $p->id)->firstOrFail();
        $two = ProjectClient::factory()->create(['project_id' => $p->id, 'email' => $one->email]);
        app(LegacyInventory::class)->process($p->workspace_id, refresh: true);
        $review = app(AccountingReview::class);
        $row = collect($review->listing($p->workspace_id))->firstWhere('clientId', $two->uuid);
        $authBefore = DB::table('project_access_tokens')->get()->toJson();
        $result = $review->review($owner, $p->workspace_id, false, $two->uuid, 'separate', (int) $row['revision'], $row['inputHash'], 'operator_verified', 'review-once');
        $this->assertNotSame($one->fresh()->entitlement_identity_id, $two->fresh()->entitlement_identity_id);
        $this->assertSame($result, $review->review($owner, $p->workspace_id, false, $two->uuid, 'separate', (int) $row['revision'], $row['inputHash'], 'operator_verified', 'review-once'));
        $this->assertSame($authBefore, DB::table('project_access_tokens')->get()->toJson());
        $this->assertSame(2, app(WorkspaceUsage::class)->measure($p->workspace_id, 'clients.max')['count']);
        app(LegacyInventory::class)->process($p->workspace_id, refresh: true);
        $this->assertSame('ready', DB::table('entitlement_client_observations')->where('project_client_id', $two->id)->value('state'));
    }

    public function test_capacity_would_deny_does_not_prevent_existing_authorized_project_creation(): void
    {
        [$p,$owner,$basic] = $this->setupWorkspace();
        $other = Workspace::factory()->create();
        $user = User::factory()->owner($other)->create(['platform_admin' => false]);
        app(SubscriptionAssignments::class)->pin($owner, $other->id, $basic->id, 0, 'free-pin');
        $existing = Project::factory()->create(['workspace_id' => $other->id]);
        $decision = app(ShadowGuard::class)->observe($other->id, 'project.create', ['projects.active.max' => 1]);
        $this->assertSame('would_deny', collect($decision['decisions'])->firstWhere('key', 'projects.active.max')['state']);
        DB::table('plan_settings')->where('id', 1)->update(['rollout_mode' => 'enforce']);
        $this->actingAs($user)->postJson('/api/admin/projects', ['title' => 'Still authorized'])->assertCreated();
        $this->assertSame(2, Project::where('workspace_id', $other->id)->count());
        $this->assertDatabaseCount('workspace_suspension_cycles', 0);
    }

    public function test_shadow_feature_cache_and_core_reads_do_not_run_capacity_scans(): void
    {
        [$p] = $this->setupWorkspace();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $core = app(ShadowGuard::class)->observe($p->workspace_id, 'task.read');
        $this->assertCount(0, DB::getQueryLog());
        $feature = app(ShadowGuard::class)->observe($p->workspace_id, 'api.read');
        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(6, count($queries));
        if (getenv('PHASE4A_METRICS') === '1') {
            fwrite(STDOUT, json_encode(['coreQueries' => 0, 'coreMicros' => $core['durationMicros'], 'featureQueries' => count($queries), 'featureMicros' => $feature['durationMicros'], 'clientScans' => 0], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('project_clients', $query['query']);
        }
        DB::disableQueryLog();
    }

    public static function driftCases(): array
    {
        return array_map(fn ($kind) => [$kind], ['client', 'invite', 'agent', 'subscription', 'identity', 'catalog']);
    }

    #[DataProvider('driftCases')]
    public function test_frozen_preview_rejects_current_accounting_invitation_agent_or_subscription_drift(string $kind): void
    {
        [$p, $owner, $basic] = $this->setupWorkspace();
        $service = app(FrozenTransitions::class);
        $options = $service->options($owner, $p->workspace_id, false);
        $preview = $service->create($owner, $p->workspace_id, false, $basic->uuid, $this->selection($options), 1, 'drift');
        match ($kind) {
            'client' => ProjectClient::factory()->create(['project_id' => $p->id]),
            'invite' => DeveloperInvitation::factory()->create(['workspace_id' => $p->workspace_id, 'invited_by' => $owner->id]),
            'agent' => ExternalAgent::factory()->create(['workspace_id' => $p->workspace_id]),
            'subscription' => DB::table('workspace_subscriptions')->where('workspace_id', $p->workspace_id)->increment('revision'),
            'catalog' => DB::table('plans')->where('id', $basic->plan_id)->update(['status' => 'archived']),
            'identity' => DB::table('entitlement_client_identities')->where('workspace_id', $p->workspace_id)->increment('revision'),
        };
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('transition_preview_stale');
        $service->read($owner, $p->workspace_id, false, $preview['id']);
    }

    public function test_accounting_review_rejects_concurrent_input_edits_without_mutating_authentication(): void
    {
        [$p, $owner] = $this->setupWorkspace();
        $client = ProjectClient::where('project_id', $p->id)->firstOrFail();
        $service = app(AccountingReview::class);
        $row = $service->listing($p->workspace_id)[0];
        $identity = $client->entitlement_identity_id;
        $client->update(['email' => 'changed@example.test']);
        try {
            $service->review($owner, $p->workspace_id, false, $client->uuid, 'separate', (int) $row['revision'], $row['inputHash'], 'operator_verified', 'stale');
            $this->fail('Stale evidence accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame($identity, $client->fresh()->entitlement_identity_id);
        $this->assertSame(0, DB::table('plan_events')->where('action', 'accounting.reviewed')->count());
    }

    public function test_multiple_owners_require_review_and_basic_api_capabilities_are_observed_separately(): void
    {
        [$p, $owner, $basic] = $this->setupWorkspace();
        User::factory()->owner($p->workspace)->create();
        $service = app(FrozenTransitions::class);
        $options = $service->options($owner, $p->workspace_id, false);
        $preview = $service->create($owner, $p->workspace_id, false, $basic->uuid, $this->selection($options), 1, 'owners');
        $this->assertSame('needs_review', $preview['state']);
        $this->assertContains('ownership_review_required', $preview['reasonCodes']);
        $this->assertSame(2, DB::table('workspace_members')->where('workspace_id', $p->workspace_id)->where('role', 'owner')->count());
        $other = Workspace::factory()->create();
        app(SubscriptionAssignments::class)->pin($owner, $other->id, $basic->id, 0, 'new-basic');
        $guard = app(ShadowGuard::class);
        $this->assertSame('would_deny', $guard->observe($other->id, 'api.key')['decisions'][0]['state']);
        $agent = $guard->observe($other->id, 'agent.key');
        $this->assertSame(['agents.enabled', 'api.agents.enabled'], array_column($agent['decisions'], 'key'));
        $this->assertSame(['would_deny'], array_values(array_unique(array_column($agent['decisions'], 'state'))));
        $this->assertSame('adapter_unavailable', collect($guard->observe($other->id, 'agent.run')['decisions'])->firstWhere('key', 'agents.runs.quota')['reasonCode']);
    }
}
