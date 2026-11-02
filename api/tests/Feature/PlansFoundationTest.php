<?php

namespace Tests\Feature;

use App\Models\ExternalAgent;
use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Plans\EntitlementRegistry;
use App\Services\Plans\EntitlementResolver;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlanConfiguration;
use App\Services\Plans\PlatformPolicyCatalog;
use App\Services\Plans\SubscriptionAssignments;
use App\Services\Plans\SubscriptionClock;
use App\Services\Plans\WorkspaceUsage;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlansFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function primary(): User
    {
        $user = User::factory()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $user->id, 'revision' => 1, 'assigned_at' => now()]);
        $this->seed(PlansFoundationSeeder::class);

        return $user;
    }

    private function draft(string $code = 'studio'): PlanVersion
    {
        return PlanVersion::whereHas('plan', fn ($q) => $q->where('code', $code))->firstOrFail();
    }

    public function test_seed_is_inert_idempotent_and_does_not_overwrite_drafts(): void
    {
        $this->seed(PlansFoundationSeeder::class);
        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('workspace_subscriptions', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->assertDatabaseHas('plan_settings', ['id' => 1, 'rollout_mode' => 'off', 'current_policy_version_id' => null]);
        $draft = $this->draft();
        $draft->update(['translations_json' => array_fill_keys(['ru', 'en', 'tr', 'zh-CN'], ['name' => 'Changed', 'description' => ''])]);
        $this->seed(PlansFoundationSeeder::class);
        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('platform_policy_versions', 1);
        $this->assertSame('Changed', $draft->fresh()->translations_json['en']['name']);
        $this->assertSame(['included' => 10, 'hard_max' => 50, 'allow_extra' => true], $draft->developer_seats_json);
    }

    public function test_registry_has_independent_namespaces_storage_and_safe_unavailable_defaults(): void
    {
        $registry = new EntitlementRegistry;
        $this->assertNotSame($registry->definition('api.agents.enabled'), $registry->definition('api.external.enabled'));
        foreach (['storage.bytes.max', 'storage.files.max'] as $key) {
            $this->assertSame('planned', $registry->definition($key)['availability']);
            $this->assertNull($registry->definition($key)['default']);
        }
        $this->assertSame('unavailable', $registry->definition('formwatch.enabled')['availability']);
        $this->assertSame(['kind' => 'boolean', 'value' => false], $registry->safeDefault('api.agents.enabled'));
        $this->assertTrue($registry->definition('developers.max')['derived']);
    }

    public function test_registry_rejects_unknown_magic_unlimited_bad_types_and_unsupported_scopes(): void
    {
        $registry = new EntitlementRegistry;
        foreach ([['unknown', ['kind' => 'boolean', 'value' => true], 'workspace'], ['clients.max', null, 'workspace'], ['clients.max', ['kind' => 'integer', 'value' => -1], 'workspace'], ['api.agents.enabled', ['kind' => 'boolean', 'value' => 1], 'workspace'], ['developers.max', ['kind' => 'integer', 'value' => 10], 'workspace'], ['clients.max', ['kind' => 'unlimited'], 'project']] as [$key, $value, $scope]) {
            try {
                $registry->validate($key, $value, $scope);
                $this->fail('Unsafe value accepted: '.$key);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['kind' => 'unlimited'], $registry->validate('clients.max', ['kind' => 'unlimited']));
    }

    public function test_fourth_and_fifth_plans_rename_and_publish_without_changing_legacy_workspace(): void
    {
        $actor = $this->primary();
        $workspace = Workspace::factory()->create();
        $before = DB::table('workspaces')->where('id', $workspace->id)->first();
        $catalog = app(PlanCatalog::class);
        $config = $catalog->configuration($this->draft());
        foreach (['fourth', 'fifth'] as $code) {
            $draft = $catalog->create($actor, $code, $config, 'create-'.$code);
            $config['translations']['en']['name'] = 'Renamed without semantics';
            $draft = $catalog->revise($actor, $draft->id, 1, $config, 'rename-'.$code);
            $published = $catalog->publish($actor, $draft->id, 2, 'publish-'.$code);
            $this->assertSame('published', $published->state);
            $this->assertSame($config['entitlements'], $catalog->configuration($published)['entitlements']);
        }
        $this->assertDatabaseCount('plans', 5);
        $this->assertEquals($before, DB::table('workspaces')->where('id', $workspace->id)->first());
        $this->assertDatabaseCount('workspace_subscriptions', 0);
    }

    public function test_revisions_idempotency_and_authority_are_checked_even_on_replay(): void
    {
        $actor = $this->primary();
        $catalog = app(PlanCatalog::class);
        $draft = $this->draft();
        $one = $catalog->publish($actor, $draft->id, 1, 'publish-once');
        $this->assertSame($one->id, $catalog->publish($actor, $draft->id, 1, 'publish-once')->id);
        foreach ([fn () => $catalog->publish($actor, $draft->id, 2, 'publish-once'), fn () => $catalog->revise($actor, $this->draft('basic')->id, 999, [], 'stale')] as $operation) {
            try {
                $operation();
                $this->fail('Conflict accepted');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $actor->forceFill(['platform_admin' => false])->save();
        $this->expectException(HttpException::class);
        $catalog->publish($actor, $draft->id, 1, 'publish-once');
    }

    public function test_published_parent_and_children_are_immutable_in_models_and_raw_database(): void
    {
        $actor = $this->primary();
        $version = app(PlanCatalog::class)->publish($actor, $this->draft()->id, 1, 'immutable');
        try {
            $version->update(['access_class' => 'free']);
            $this->fail('Model mutation accepted');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        foreach ([fn () => DB::table('plan_entitlement_values')->where('plan_version_id', $version->id)->update(['value_json' => json_encode(['kind' => 'integer', 'value' => 3])]), fn () => DB::table('plan_entitlement_values')->insert(['plan_version_id' => $version->id, 'entitlement_key' => 'storage.bytes.max', 'scope_kind' => 'workspace', 'value_json' => json_encode(['kind' => 'integer', 'value' => 123])]), fn () => DB::table('plan_versions')->where('id', $version->id)->update(['revision' => 99]), fn () => DB::table('plan_entitlement_values')->where('plan_version_id', $version->id)->delete(), fn () => DB::table('plan_versions')->where('id', $version->id)->delete()] as $write) {
            try {
                $write();
                $this->fail('Raw published mutation accepted');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_subscription_is_pinned_and_owner_seats_are_derived(): void
    {
        $actor = $this->primary();
        $catalog = app(PlanCatalog::class);
        $version = $catalog->publish($actor, $this->draft()->id, 1, 'pin-v1');
        $workspace = Workspace::factory()->create();
        $catalog->publish($actor, $this->draft('basic')->id, 1, 'publish-fallback');
        $policy = app(PlatformPolicyCatalog::class)->publish($actor, PlatformPolicyVersion::firstOrFail()->id, 1, 'publish-policy');
        $assignment = app(SubscriptionAssignments::class)->pin($actor, $workspace->id, $version->id, 2, 'pin-workspace', serviceDays: 7, policyId: $policy->id);
        $snapshot = app(EntitlementResolver::class)->resolve($workspace->id);
        $this->assertSame('available', $snapshot['state']);
        $this->assertSame(['kind' => 'integer', 'value' => 12], $snapshot['values']['developers.max']);
        $next = $catalog->nextDraft($actor, $version->plan_id, 2, 'next-draft');
        $catalog->publish($actor, $next->id, 1, 'pin-v2');
        $this->assertSame($version->id, app(EntitlementResolver::class)->resolve($workspace->id)['version_id']);
        $this->assertSame($assignment->current_period_id, $assignment->fresh()->current_period_id);
    }

    public function test_paid_overrides_cannot_upgrade_free_and_agent_api_is_independent(): void
    {
        $actor = $this->primary();
        $catalog = app(PlanCatalog::class);
        $basic = $catalog->publish($actor, $this->draft('basic')->id, 1, 'basic');
        $workspace = Workspace::factory()->create();
        $subscriptions = app(SubscriptionAssignments::class);
        $subscriptions->pin($actor, $workspace->id, $basic->id, 0, 'free');
        try {
            $subscriptions->override($actor, $workspace->id, 1, 'api.external.enabled', ['kind' => 'boolean', 'value' => true], CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->addDay(), 'manual', 'override');
            $this->fail('Free upgraded by override');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $freelancer = $catalog->configuration($this->draft('freelancer'));
        $this->assertTrue($freelancer['entitlements']['api.agents.enabled']['value']);
        $this->assertFalse($freelancer['entitlements']['api.external.enabled']['value']);
        $this->assertSame(['kind' => 'unlimited'], $freelancer['entitlements']['clients.max']);
    }

    public function test_clock_distinguishes_trial_paid_grace_and_exact_utc_boundaries(): void
    {
        $clock = new SubscriptionClock;
        $verified = CarbonImmutable::parse('2026-10-25T01:30:00+03:00');
        $trial = $clock->period('trial', $verified, 7, 7);
        $paid = $clock->period('paid', $verified, 7, 7);
        $this->assertNull($trial['grace_ends_at']);
        $this->assertSame(604800, $trial['service_ends_at']->getTimestamp() - $trial['starts_at']->getTimestamp());
        $this->assertSame('trial', $clock->phase($trial, $trial['service_ends_at']->subMicrosecond())['phase']);
        $this->assertSame('fallback_required', $clock->phase($trial, $trial['service_ends_at'])['phase']);
        $this->assertSame('grace', $clock->phase($paid, $paid['service_ends_at'])['phase']);
        $this->assertSame('fallback_required', $clock->phase($paid, $paid['grace_ends_at'])['phase']);
    }

    public function test_missing_subscription_storage_and_accounting_fail_explicitly(): void
    {
        $workspace = Workspace::factory()->create();
        $this->assertSame('unavailable', app(EntitlementResolver::class)->resolve($workspace->id)['state']);
        $usage = app(WorkspaceUsage::class);
        $this->assertSame('unavailable', $usage->measure($workspace->id, 'storage.bytes.max')['state']);
        $this->assertSame('incomplete', $usage->measure($workspace->id, 'clients.max')['state']);
    }

    public function test_seats_have_one_source_and_bounded_primary_approved_extras(): void
    {
        $registry = new EntitlementRegistry;
        foreach ([['basic', 1], ['freelancer', 1], ['studio', 10]] as [$code, $count]) {
            $policy = PlansFoundationSeeder::configuration($code)['developer_seats'];
            $this->assertSame($count, $registry->seats($policy)['value']);
        }
        $studio = PlansFoundationSeeder::configuration('studio')['developer_seats'];
        $this->assertSame(50, $registry->seats($studio, 40)['value']);
        foreach ([41, -1] as $extra) {
            try {
                $registry->seats($studio, $extra);
                $this->fail('Unsafe extra seats accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $project = Project::factory()->create();
        $this->ownerFor($project);
        ExternalAgent::create(['workspace_id' => $project->workspace_id, 'name' => 'Not a human', 'active' => true]);
        $usage = app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max');
        $this->assertSame(1, $usage['observed_human_members']);
        $this->assertSame('incomplete', $usage['state']);
        $this->assertDatabaseMissing('plan_entitlement_values', ['entitlement_key' => 'developers.max']);
    }

    public function test_catalog_requires_all_available_keys_and_does_not_accept_arbitrary_dsl(): void
    {
        $actor = $this->primary();
        $catalog = app(PlanCatalog::class);
        $original = $catalog->configuration($this->draft());
        $missing = $original;
        unset($missing['entitlements']['clients.max']);
        $unknown = $original;
        $unknown['entitlements']['eval.php'] = ['kind' => 'boolean', 'value' => true];
        $storage = $original;
        $storage['entitlements']['storage.bytes.max'] = ['kind' => 'integer', 'value' => 100];
        foreach ([$missing, $unknown, $storage] as $i => $config) {
            try {
                $catalog->create($actor, 'invalid-'.$i, $config, 'invalid-'.$i);
                $this->fail('Invalid registry configuration accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('plans', 3);
    }

    public function test_policy_publication_is_immutable_inert_and_requires_published_targets(): void
    {
        $actor = $this->primary();
        $policy = PlatformPolicyVersion::firstOrFail();
        $policies = app(PlatformPolicyCatalog::class);
        try {
            $policies->publish($actor, $policy->id, 1, 'premature-policy');
            $this->fail('Draft targets published');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        foreach (['basic', 'studio'] as $code) {
            app(PlanCatalog::class)->publish($actor, $this->draft($code)->id, 1, 'target-'.$code);
        }
        $published = $policies->publish($actor, $policy->id, 1, 'policy');
        $this->assertSame('published', $published->state);
        $this->assertDatabaseHas('plan_settings', ['current_policy_version_id' => null, 'rollout_mode' => 'off']);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        try {
            DB::table('platform_policy_versions')->where('id', $policy->id)->update(['trial_days' => 50]);
            $this->fail('Immutable policy changed');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_archive_keeps_history_and_rules_reject_unsafe_downgrade_policies(): void
    {
        $actor = $this->primary();
        $catalog = app(PlanCatalog::class);
        $basic = $catalog->publish($actor, $this->draft('basic')->id, 1, 'rule-basic');
        $studio = $catalog->publish($actor, $this->draft()->id, 1, 'rule-studio');
        $rule = ['initiator' => 'owner', 'approval' => 'none', 'timing' => 'immediate', 'enabled' => false, 'overage_policy' => ['active' => 'select_and_suspend', 'storage' => 'read_only']];
        $id = $catalog->transition($actor, $studio->id, $basic->id, $rule, null, 'rule-create');
        $this->assertDatabaseHas('plan_transition_rules', ['id' => $id, 'revision' => 1, 'enabled' => false]);
        $rule['overage_policy']['active'] = 'block_growth';
        try {
            $catalog->transition($actor, $studio->id, $basic->id, $rule, 1, 'rule-unsafe');
            $this->fail('Permanent active overage accepted');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $catalog->archive($actor, $basic->plan_id, 2, 'archive');
        $this->assertDatabaseHas('plan_versions', ['id' => $basic->id, 'state' => 'published']);
    }

    public function test_composite_foreign_keys_reject_wrong_plan_and_workspace_references(): void
    {
        $actor = $this->primary();
        $catalog = app(PlanCatalog::class);
        $basic = $catalog->publish($actor, $this->draft('basic')->id, 1, 'fk-basic');
        try {
            DB::table('plans')->where('id', $this->draft()->plan_id)->update(['published_version_id' => $basic->id]);
            $this->fail('Cross plan version accepted');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();
        $assignments = app(SubscriptionAssignments::class);
        $one = $assignments->pin($actor, $a->id, $basic->id, 0, 'fk-one');
        $two = $assignments->pin($actor, $b->id, $basic->id, 0, 'fk-two');
        try {
            DB::table('workspace_subscriptions')->where('id', $one->id)->update(['current_period_id' => $two->current_period_id]);
            $this->fail('Foreign period accepted');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_non_primary_platform_admin_and_workspace_owner_have_no_catalog_authority(): void
    {
        $this->primary();
        foreach ([true, false] as $platformAdmin) {
            $user = User::factory()->create(['platform_admin' => $platformAdmin]);
            try {
                app(PlanCatalog::class)->publish($user, $this->draft()->id, 1, 'not-primary');
                $this->fail('Non-primary catalog authority accepted');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_explicit_quota_and_enum_serialization_and_configuration_size_bounds(): void
    {
        $registry = new EntitlementRegistry;
        $quota = ['kind' => 'quota', 'limit' => 17, 'window' => 'calendar_month_utc', 'unit' => 'request'];
        $this->assertSame($quota, json_decode(json_encode($registry->validate('ai.requests.quota', $quota)), true));
        $this->assertSame(['kind' => 'enum', 'value' => 'full'], $registry->validate('branding.level', ['kind' => 'enum', 'value' => 'full']));
        foreach ([['kind' => 'quota', 'limit' => 10, 'window' => 'subscription', 'unit' => 'request'], ['kind' => 'quota', 'limit' => null, 'window' => 'calendar_month_utc', 'unit' => 'request']] as $invalid) {
            try {
                $registry->validate('ai.requests.quota', $invalid);
                $this->fail('Invalid quota accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $configuration = PlansFoundationSeeder::configuration('basic');
        $configuration['translations']['en']['description'] = str_repeat('x', 65537);
        $this->expectException(\InvalidArgumentException::class);
        app(PlanConfiguration::class)->validate($configuration);
    }

    public function test_audit_failure_rolls_back_catalog_mutation(): void
    {
        $actor = $this->primary();
        $draft = $this->draft();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'plan_events')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(PlanCatalog::class)->publish($actor, $draft->id, 1, 'audit-rollback');
            $this->fail('Unaudited publication committed');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertSame('draft', $draft->fresh()->state);
        $this->assertNull($draft->plan->fresh()->published_version_id);
    }

    public function test_available_resolution_is_batched_and_expired_paid_service_never_fabricates_fallback(): void
    {
        $actor = $this->primary();
        $catalog = app(PlanCatalog::class);
        $version = $catalog->publish($actor, $this->draft()->id, 1, 'clock-paid');
        $catalog->publish($actor, $this->draft('basic')->id, 1, 'clock-free');
        $policy = app(PlatformPolicyCatalog::class)->publish($actor, PlatformPolicyVersion::firstOrFail()->id, 1, 'clock-policy');
        $workspace = Workspace::factory()->create();
        $start = CarbonImmutable::parse('2026-01-01T00:00:00.123456Z');
        $subscription = app(SubscriptionAssignments::class)->pin($actor, $workspace->id, $version->id, 0, 'clock-pin', $start, 7, $policy->id);
        $this->assertSame('123456', $subscription->currentPeriod->starts_at->format('u'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $snapshot = app(EntitlementResolver::class)->resolve($workspace->id, $start->addDays(7));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('grace', $snapshot['phase']);
        $this->assertLessThanOrEqual(5, $queries);
        $this->assertArrayNotHasKey('storage.bytes.max', $snapshot['values']);
        $expired = app(EntitlementResolver::class)->resolve($workspace->id, $start->addDays(14));
        $this->assertSame('unavailable', $expired['state']);
        $this->assertSame([], $expired['values']);
        $this->assertSame($version->id, $subscription->currentPeriod->entitlement_version_id);
    }

    public function test_prepared_storage_and_epoch_contracts_have_no_automatic_workers_or_legacy_values(): void
    {
        $draft = PlanVersion::factory()->create();
        $this->assertSame('draft', $draft->state);
        foreach (['workspace_file_usage', 'workspace_storage_reservations', 'workspace_execution_epochs', 'entitlement_capacity_reservations', 'plan_previews', 'plan_preview_items'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['agent_runs', 'webhook_events', 'webhook_deliveries', 'integration_requests'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'execution_epoch'));
        }
        $this->assertFalse(Schema::hasTable('entitlement_quota_operations'));
        $this->assertFalse(Schema::hasTable('retention_jobs'));
    }

    public function test_actor_deletion_anonymizes_references_without_removing_published_history(): void
    {
        $actor = $this->primary();
        $version = app(PlanCatalog::class)->publish($actor, $this->draft()->id, 1, 'actor-history');
        DB::table('platform_plan_authority')->where('id', 1)->update(['primary_user_id' => null, 'revision' => 2]);
        DB::table('users')->where('id', $actor->id)->delete();
        $this->assertSame('published', $version->fresh()->state);
        $this->assertNull($version->fresh()->published_by);
        $this->assertDatabaseHas('plan_events', ['action' => 'plan.publish', 'actor_user_id' => null]);
    }

    public function test_resolver_rejects_a_forged_paid_override_on_a_free_period(): void
    {
        $actor = $this->primary();
        $version = app(PlanCatalog::class)->publish($actor, $this->draft('basic')->id, 1, 'override-basic');
        $workspace = Workspace::factory()->create();
        $subscription = app(SubscriptionAssignments::class)->pin($actor, $workspace->id, $version->id, 0, 'override-free');
        DB::table('workspace_entitlement_overrides')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'subscription_id' => $subscription->id, 'entitlement_key' => 'api.external.enabled', 'scope_kind' => 'workspace', 'value_json' => json_encode(['kind' => 'boolean', 'value' => true]), 'starts_at' => now('UTC')->subMinute(), 'ends_at' => now('UTC')->addDay(), 'reason_code' => 'synthetic', 'approved_by' => $actor->id, 'revision' => 1]);
        $snapshot = app(EntitlementResolver::class)->resolve($workspace->id);
        $this->assertSame('unavailable', $snapshot['state']);
        $this->assertSame('override_invalid', $snapshot['reason']);
        $this->assertSame([], $snapshot['values']);
    }
}
