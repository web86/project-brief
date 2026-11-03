<?php

namespace App\Services\Plans;

use App\Models\Plans\Plan;
use App\Models\Plans\PlanVersion;
use App\Models\Plans\SubscriptionPeriod;
use App\Models\Plans\WorkspaceSubscription;
use App\Services\IntegrationWrites;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LegacyInventory
{
    public function __construct(private readonly ClientCountingIdentities $clients, private readonly InvitationOccupancy $invitations) {}

    /** Explicit operator bootstrap only. No scheduler, installer, public editor or user path calls this. */
    public function bootstrap(bool $extendCohort = false): object
    {
        return DB::transaction(function () use ($extendCohort): object {
            DB::table('plan_settings')->insertOrIgnore(['id' => 1, 'registry_revision' => EntitlementRegistry::REVISION, 'rollout_mode' => 'off', 'revision' => 1]);
            $settings = DB::table('plan_settings')->where('id', 1)->lockForUpdate()->first();
            if ($settings->legacy_version_id === null) {
                abort_if(Plan::where('code', 'legacy-compatibility-v1')->exists(), 409, 'Reserved compatibility identifier requires operator review.');
                app(PlanCatalog::class)->mirrorDefinitions();
                $values = [];
                foreach (app(EntitlementRegistry::class)->definitions() as $key => $d) {
                    if ($d['derived'] || $d['availability'] !== 'available') {
                        continue;
                    }
                    $values[$key] = match ($d['kind']) {
                        'integer' => ['kind' => 'unlimited'],
                        'boolean' => ['kind' => 'boolean', 'value' => true],
                        'enum' => ['kind' => 'enum', 'value' => 'full'],
                    };
                }
                $config = app(PlanConfiguration::class)->validate(['visibility' => 'hidden', 'access_class' => 'compatibility', 'translations' => array_fill_keys(['ru', 'en', 'tr', 'zh-CN'], ['name' => 'Legacy compatibility', 'description' => 'Observe only']), 'price' => ['mode' => 'free', 'amount_minor' => null, 'currency' => null, 'scale' => 2, 'interval' => null], 'developer_seats' => ['included' => ['kind' => 'unlimited'], 'hard_max' => ['kind' => 'unlimited'], 'allow_extra' => false], 'entitlements' => $values]);
                $plan = Plan::create(['code' => 'legacy-compatibility-v1', 'visibility' => 'hidden', 'status' => 'draft', 'revision' => 1, 'sort_order' => 10000]);
                $version = PlanVersion::create(['plan_id' => $plan->id, 'number' => 1, 'state' => 'draft', 'access_class' => 'compatibility', 'translations_json' => $config['translations'], 'price_json' => $config['price'], 'developer_seats_json' => $config['developer_seats'], 'registry_revision' => EntitlementRegistry::REVISION, 'revision' => 1]);
                foreach ($values as $key => $value) {
                    DB::table('plan_entitlement_values')->insert(['plan_version_id' => $version->id, 'entitlement_key' => $key, 'scope_kind' => 'workspace', 'value_json' => json_encode($value, JSON_THROW_ON_ERROR)]);
                }
                $version->update(['state' => 'published', 'revision' => 2, 'content_hash' => PlanConfiguration::versionHash($config), 'published_at' => now('UTC')]);
                $plan->update(['status' => 'published', 'published_version_id' => $version->id]);
                DB::table('plan_settings')->where('id', 1)->update(['legacy_version_id' => $version->id, 'revision' => $settings->revision + 1]);
                $this->event(null, $plan->uuid, 'inventory.compatibility_created', ['version_id' => $version->id]);
            } else {
                $version = PlanVersion::with('plan')->findOrFail($settings->legacy_version_id);
                abort_unless($version->state === 'published' && $version->access_class === 'compatibility' && $version->plan->visibility === 'hidden' && $version->plan->status === 'published' && hash_equals($version->content_hash, PlanConfiguration::versionHash(app(PlanCatalog::class)->configuration($version))), 409, 'Compatibility configuration requires operator review.');
            }
            $cohort = DB::table('plan_inventory_cohorts')->where('id', 1)->lockForUpdate()->first();
            $cutoff = (int) DB::table('workspaces')->max('id');
            if (! $cohort) {
                DB::table('plan_inventory_cohorts')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'workspace_cutoff_id' => $cutoff, 'captured_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), 'revision' => 1]);
            } elseif ($extendCohort && $cutoff > $cohort->workspace_cutoff_id) {
                DB::table('plan_inventory_cohorts')->where('id', 1)->update(['workspace_cutoff_id' => $cutoff, 'revision' => $cohort->revision + 1]);
            }
            $current = DB::table('plan_inventory_cohorts')->where('id', 1)->first();
            if (! $cohort || $current->revision !== $cohort->revision) {
                $this->event(null, $current->uuid, 'inventory.cohort_captured', ['workspace_cutoff_id' => $cutoff, 'revision' => $current->revision]);
            }

            return $current;
        }, 3);
    }

    public function process(int $workspaceId, int $cohortId = 1, bool $refresh = false): array
    {
        return DB::transaction(function () use ($workspaceId, $cohortId, $refresh): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();
            $cohort = DB::table('plan_inventory_cohorts')->where('id', $cohortId)->first();
            abort_unless($cohort && $workspaceId <= $cohort->workspace_cutoff_id, 409, 'Workspace outside captured legacy cohort.');
            $item = DB::table('plan_inventory_items')->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if ($item && in_array($item->state, ['processed', 'needs_review'], true) && ! $refresh) {
                return ['state' => 'skipped', 'new_mappings' => 0, 'subscription_created' => false];
            }
            $subscription = DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            $created = ! $subscription;
            if ($created) {
                $versionId = DB::table('plan_settings')->where('id', 1)->value('legacy_version_id');
                abort_unless($versionId, 409, 'Operator compatibility bootstrap is required.');
                $subscription = WorkspaceSubscription::create(['workspace_id' => $workspaceId, 'approved_extra_seats' => 0, 'enforcement_mode' => 'observe', 'revision' => 1, 'materialized_phase' => 'compatibility']);
                $period = SubscriptionPeriod::create(['workspace_id' => $workspaceId, 'subscription_id' => $subscription->id, 'kind' => 'compatibility', 'entitlement_version_id' => $versionId, 'starts_at' => CarbonImmutable::now('UTC'), 'approval_kind' => 'migration']);
                $subscription->update(['current_period_id' => $period->id]);
            }
            $start = ! $item || ($refresh && $item->state !== 'pending') ? 0 : (int) $item->last_client_id;
            $cutoff = ! $item || ($item->state === 'failed' && (int) $item->client_cutoff_id === 0) || ($refresh && $item->state !== 'pending') ? (int) DB::table('project_clients as c')->join('projects as p', 'p.id', '=', 'c.project_id')->where('p.workspace_id', $workspaceId)->max('c.id') : (int) $item->client_cutoff_id;
            $before = DB::table('project_clients as c')->join('projects as p', 'p.id', '=', 'c.project_id')->where('p.workspace_id', $workspaceId)->whereNull('c.entitlement_identity_id')->count();
            $chunk = $this->clients->reconcile($workspaceId, $start, $cutoff);
            $invitationStart = ! $item || ($refresh && $item->state !== 'pending') ? 0 : (int) $item->last_invitation_id;
            $invitationCutoff = ! $item || ($item->state === 'failed' && (int) $item->invitation_cutoff_id === 0) || ($refresh && $item->state !== 'pending') ? (int) DB::table('developer_invitations')->where('workspace_id', $workspaceId)->max('id') : (int) $item->invitation_cutoff_id;
            $invitations = $this->invitations->reconcile($workspaceId, $invitationStart, $invitationCutoff);
            $reasons = [];
            if (DB::table('workspace_members')->where('workspace_id', $workspaceId)->where('status', 'active')->where('role', 'owner')->distinct()->count('user_id') !== 1) {
                $reasons[] = 'ownership_review_required';
            }
            if (DB::table('entitlement_client_observations')->where('workspace_id', $workspaceId)->where('state', 'needs_review')->exists()) {
                $reasons[] = 'client_accounting_review_required';
            }
            if ($chunk['done'] && $before > $chunk['new_mappings']) {
                $reasons[] = 'client_mapping_incomplete';
            }
            $state = $chunk['done'] && $invitations['done'] ? ($reasons ? 'needs_review' : 'processed') : 'pending';
            $revision = ($item?->revision ?? 0) + 1;
            DB::table('plan_inventory_items')->updateOrInsert(['workspace_id' => $workspaceId], ['cohort_id' => $cohortId, 'state' => $state, 'last_client_id' => $chunk['last_id'], 'client_cutoff_id' => $cutoff, 'last_invitation_id' => $invitations['last_id'], 'invitation_cutoff_id' => $invitationCutoff, 'revision' => $revision, 'reason_codes' => json_encode($reasons, JSON_THROW_ON_ERROR), 'observed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
            $this->event($workspaceId, $workspace->uuid, 'inventory.workspace_accounted', ['revision' => $revision, 'state' => $state, 'subscription_created' => $created, 'missing_before' => $before, 'missing_after' => $before - $chunk['new_mappings'], 'processed_links' => $chunk['processed'], 'reason_codes' => $reasons]);

            return ['state' => $state, 'new_mappings' => $chunk['new_mappings'], 'subscription_created' => $created];
        }, 3);
    }

    public function failed(int $workspaceId): void
    {
        DB::transaction(function () use ($workspaceId): void {
            IntegrationWrites::lockWorkspace($workspaceId);
            $item = DB::table('plan_inventory_items')->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if ($item && in_array($item->state, ['processed', 'needs_review'], true)) {
                return;
            }
            DB::table('plan_inventory_items')->updateOrInsert(['workspace_id' => $workspaceId], ['cohort_id' => 1, 'state' => 'failed', 'last_client_id' => $item?->last_client_id ?? 0, 'client_cutoff_id' => $item?->client_cutoff_id ?? 0, 'revision' => ($item?->revision ?? 0) + 1, 'reason_codes' => '["inventory_operation_failed"]', 'observed_at' => now('UTC')]);
        }, 3);
    }

    private function event(?int $workspaceId, string $uuid, string $action, array $metadata): void
    {
        DB::table('plan_events')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'aggregate_type' => 'inventory', 'aggregate_uuid' => $uuid, 'actor_kind' => 'operator', 'action' => $action, 'result' => 'applied', 'diff_json' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }
}
