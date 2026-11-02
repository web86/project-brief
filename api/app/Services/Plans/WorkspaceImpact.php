<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use Illuminate\Support\Facades\DB;

final class WorkspaceImpact
{
    public function __construct(private readonly WorkspaceUsage $usage, private readonly EntitlementRegistry $registry) {}

    /** Pure shadow comparison. This service is deliberately absent from execution/authorization paths. */
    public function preview(int $workspaceId, ?string $targetUuid = null): array
    {
        return DB::transaction(function () use ($workspaceId, $targetUuid): array {
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();
            abort_unless($workspace, 404);
            $usage = $this->usage->snapshot($workspaceId);
            $subscription = DB::table('workspace_subscriptions as s')->leftJoin('subscription_periods as p', 'p.id', '=', 's.current_period_id')->leftJoin('plan_versions as v', 'v.id', '=', 'p.entitlement_version_id')->where('s.workspace_id', $workspaceId)->select(['s.uuid', 's.revision', 's.enforcement_mode', 's.approved_extra_seats', 'p.kind', 'v.uuid as version_uuid'])->first();
            $item = DB::table('plan_inventory_items')->where('workspace_id', $workspaceId)->first();
            $global = DB::table('plan_settings')->where('id', 1)->value('rollout_mode') ?? 'off';
            $reasons = array_values(array_unique(array_merge(...array_values(array_map(fn ($m) => in_array($m['reason'], ['not_a_capacity_metric', 'capacity_or_quota_adapter_unavailable'], true) ? [] : $m['reason_codes'], $usage)))));
            $ready = collect($usage)->only(['projects.active.max', 'developers.max', 'clients.max', 'agents.active.max'])->every(fn ($m) => $m['state'] === 'ready');
            $result = ['workspaceId' => $workspace->uuid, 'asOf' => $usage['clients.max']['as_of'], 'inventory' => ['state' => $item?->state ?? 'uninitialized', 'revision' => $item ? (string) $item->revision : null, 'observedAt' => $item?->observed_at], 'accountingState' => $ready ? 'ready' : (count(array_filter($reasons, fn ($r) => str_contains($r, 'review'))) ? 'needs_review' : 'incomplete'), 'reasonCodes' => $reasons, 'subscription' => $subscription ? ['id' => $subscription->uuid, 'revision' => (string) $subscription->revision, 'kind' => $subscription->kind, 'versionId' => $subscription->version_uuid, 'configuredMode' => $subscription->enforcement_mode] : null, 'policy' => ['configuredGlobalMode' => $global, 'effectiveMode' => 'observe', 'executionGuardsEnabled' => false, 'reasonCodes' => $global === 'enforce' || $subscription?->enforcement_mode === 'enforce' ? ['enforcement_activation_unavailable'] : []], 'usage' => $usage, 'features' => $this->features($workspaceId), 'target' => null, 'decisions' => [], 'previewOnly' => true, 'dataPreserved' => true, 'authorization' => 'existing_permissions_unchanged', 'driftWarning' => 'diagnostic_snapshot_not_a_reservation'];
            if ($targetUuid === null) {
                return $result;
            }
            $target = PlanVersion::with('plan')->where('uuid', $targetUuid)->where('state', 'published')->where('access_class', '<>', 'compatibility')->firstOrFail();
            abort_if($target->plan->status === 'archived', 422);
            $config = app(PlanCatalog::class)->configuration($target);
            app(PlanConfiguration::class)->validate($config);
            abort_unless(hash_equals($target->content_hash, PlanConfiguration::versionHash($config)), 409);
            $limits = $config['entitlements'];
            $limits['developers.max'] = $this->registry->seats($config['developer_seats']);
            $result['target'] = ['versionId' => $target->uuid, 'number' => $target->number, 'revision' => (string) $target->revision, 'contentHash' => $target->content_hash, 'registryRevision' => (string) $target->registry_revision, 'limits' => $limits, 'approvedExtraSeatsSimulated' => 0];
            $definitions = $this->registry->definitions();
            $priority = ['projects.active.max', 'developers.max', 'clients.max', 'agents.active.max'];
            foreach ([...$priority, ...array_diff(array_keys($definitions), $priority)] as $key) {
                $definition = $definitions[$key];
                $measurement = in_array($definition['kind'], ['boolean', 'enum'], true) ? ($result['features'][$key] ?? ['state' => 'unavailable', 'count' => null]) : $usage[$key];
                $limit = $limits[$key] ?? null;
                $decision = ['key' => $key, 'state' => 'unknown', 'actual' => $measurement['count'], 'measurementState' => $measurement['state'], 'limit' => $limit, 'excess' => null, 'reasonCodes' => $measurement['reason_codes'] ?? []];
                if ($definition['availability'] !== 'available' || $measurement['state'] !== 'ready' || $limit === null) {
                    $decision['reasonCodes'][] = 'accounting_or_adapter_unavailable';
                } elseif ($limit['kind'] === 'unlimited') {
                    $decision['state'] = 'would_allow';
                    $decision['excess'] = 0;
                } elseif ($limit['kind'] === 'integer') {
                    $decision['excess'] = max(0, $measurement['count'] - $limit['value']);
                    $decision['state'] = $decision['excess'] > 0 ? 'would_deny' : 'would_allow';
                    $decision['reasonCodes'] = $decision['excess'] ? ['target_capacity_exceeded'] : [];
                } elseif ($limit['kind'] === 'boolean') {
                    $decision['state'] = $measurement['count'] > 0 && ! $limit['value'] ? 'would_deny' : 'would_allow';
                    $decision['reasonCodes'] = $decision['state'] === 'would_deny' ? ['configured_feature_would_lose_access'] : [];
                } else {
                    // Branding field-to-tier enforcement is intentionally not approved yet.
                    $decision['reasonCodes'][] = 'branding_field_policy_review_required';
                }
                $result['decisions'][] = $decision;
            }

            return $result;
        });
    }

    private function features(int $workspaceId): array
    {
        $base = ['state' => 'ready', 'count' => 0, 'provenance' => 'configured_resources_not_execution_history', 'reason_codes' => []];
        $result = [];
        foreach (['agents.enabled', 'api.agents.enabled', 'api.external.enabled', 'webhooks.enabled', 'sso.enabled', 'branding.level'] as $key) {
            $result[$key] = $base;
        }
        $result['agents.enabled']['count'] = DB::table('external_agents')->where('workspace_id', $workspaceId)->where('active', true)->whereNull('deleted_at')->count();
        $scanned = 0;
        DB::table('integration_tokens')->where('workspace_id', $workspaceId)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->orderBy('id')->chunkById(200, function ($tokens) use (&$result, &$scanned): bool {
            foreach ($tokens as $token) {
                if (++$scanned > 20000) {
                    foreach (['api.agents.enabled', 'api.external.enabled'] as $key) {
                        $result[$key] = ['state' => 'incomplete', 'count' => null, 'reason_codes' => ['feature_scan_limit']];
                    }

                    return false;
                }
                $scopes = json_decode($token->scopes, true, 32, JSON_THROW_ON_ERROR);
                if ($token->external_agent_id || $token->agent_shared) {
                    $result['api.agents.enabled']['count']++;
                } elseif (array_diff($scopes, ['clients:sso'])) {
                    $result['api.external.enabled']['count']++;
                }
            }

            return true;
        });
        $result['webhooks.enabled']['count'] = DB::table('webhook_endpoints')->where('workspace_id', $workspaceId)->where('enabled', true)->count();
        $result['sso.enabled']['count'] = DB::table('integration_sources')->where('workspace_id', $workspaceId)->whereNull('archived_at')->where('sso_enabled', true)->count();
        $branding = DB::table('workspaces')->where('id', $workspaceId)->value('branding');
        $result['branding.level'] = array_replace($base, ['state' => 'incomplete', 'count' => $branding && $branding !== '[]' && $branding !== '{}' ? 1 : 0, 'reason_codes' => ['branding_field_policy_review_required']]);

        return $result;
    }

    /** Coverage is persisted metadata; live accounting and overages are explicitly bounded to this page. */
    public function inventory(int $after = 0, int $limit = 25, ?string $targetUuid = null): array
    {
        $rows = DB::table('workspaces')->where('id', '>', $after)->orderBy('id')->limit($limit)->get(['id', 'uuid']);
        $data = [];
        $overages = [];
        foreach ($rows as $row) {
            $report = $this->preview($row->id, $targetUuid);
            foreach ($report['decisions'] as $decision) {
                if ($decision['state'] === 'would_deny') {
                    $overages[$decision['key']] = ($overages[$decision['key']] ?? 0) + 1;
                }
            }
            $data[] = ['workspaceId' => $row->uuid, 'accountingState' => $report['accountingState'], 'inventory' => $report['inventory'], 'subscription' => $report['subscription'], 'reasonCodes' => $report['reasonCodes'], 'asOf' => $report['asOf']];
        }
        $total = DB::table('workspaces')->count();
        $coverage = DB::table('plan_inventory_items')->select('state')->selectRaw('COUNT(*) as count')->groupBy('state')->pluck('count', 'state')->all();
        $initialized = DB::table('workspace_subscriptions')->count();

        return ['data' => $data, 'coverage' => ['total' => $total, 'uninitialized' => $total - $initialized, 'compatibilityObserve' => DB::table('workspace_subscriptions as s')->join('subscription_periods as p', 'p.id', '=', 's.current_period_id')->where('s.enforcement_mode', 'observe')->where('p.kind', 'compatibility')->count(), 'checkpointStates' => $coverage, 'provenance' => 'stored_inventory_checkpoints'], 'pageSummary' => ['scope' => 'current_page', 'ready' => collect($data)->where('accountingState', 'ready')->count(), 'needsReview' => collect($data)->where('accountingState', 'needs_review')->count(), 'incompleteAccounting' => collect($data)->where('accountingState', 'incomplete')->count(), 'potentialOverages' => $overages], 'meta' => ['nextAfter' => (string) ($rows->last()?->id ?? $after), 'hasMore' => DB::table('workspaces')->where('id', '>', $rows->last()?->id ?? $after)->exists(), 'limit' => $limit]];
    }
}
