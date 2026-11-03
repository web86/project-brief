<?php

namespace App\Services\Plans;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ShadowGuard
{
    public const OPERATIONS = [
        'project.create' => ['projects.active.max', 'clients.max'], 'project.activate' => ['projects.active.max', 'clients.max'], 'project.restore' => ['projects.active.max', 'clients.max'],
        'project.reduce' => [], 'project.write' => [], 'task.read' => [], 'task.write' => [], 'file.write' => ['storage.bytes.max', 'storage.files.max'],
        'developer.invite' => ['developers.max'], 'developer.accept' => ['developers.max'], 'developer.change' => ['developers.max'],
        'client.change' => ['clients.max'], 'client.access' => [], 'agent.change' => ['agents.enabled', 'agents.active.max'], 'agent.assign' => ['agents.enabled'],
        'agent.run' => ['agents.enabled', 'api.agents.enabled', 'agents.runs.quota'], 'agent.read' => ['agents.enabled', 'api.agents.enabled'], 'agent.write' => ['agents.enabled', 'api.agents.enabled'],
        'agent.key' => ['agents.enabled', 'api.agents.enabled'], 'api.key' => ['api.external.enabled'], 'api.read' => ['api.external.enabled'], 'api.write' => ['api.external.enabled'],
        'webhook.manage' => ['webhooks.enabled'], 'webhook.send' => ['webhooks.enabled'], 'sso.use' => ['sso.enabled'], 'source.provision' => ['api.external.enabled'],
        'branding.write' => ['branding.level'], 'safe.reduce' => [],
    ];

    /** Advisory only. Failures never change authorization, response or business transaction. No persistent permit/cache. */
    public function observe(int $workspaceId, string $operation, array $deltas = []): array
    {
        $started = hrtime(true);
        try {
            $keys = self::OPERATIONS[$operation] ?? throw new \InvalidArgumentException('Unknown shadow operation.');
            $request = app()->bound('request') ? request() : null;
            $current = DB::transactionLevel() > 0;
            $cacheKey = self::class.'.'.$workspaceId;
            $snapshot = $current || ! $request?->route() ? null : $request?->attributes->get($cacheKey);
            if (! $snapshot || ($snapshot['valid_until'] !== null && CarbonImmutable::parse($snapshot['valid_until']) <= CarbonImmutable::now('UTC'))) {
                $snapshot = $keys ? app(EntitlementResolver::class)->resolve($workspaceId, current: $current) : ['state' => 'available', 'values' => [], 'valid_until' => null];
                if (! $current && $keys && $request?->route()) {
                    $request?->attributes->set($cacheKey, $snapshot);
                }
            }
            $needsCounts = count(array_intersect($keys, ['projects.active.max', 'clients.max', 'developers.max', 'agents.active.max'])) > 0;
            $usage = $needsCounts ? app(WorkspaceUsage::class)->snapshot($workspaceId, $current, $keys) : [];
            $decisions = [];
            foreach ($keys as $key) {
                $definition = app(EntitlementRegistry::class)->definition($key);
                $limit = $snapshot['values'][$key] ?? null;
                $measurement = $usage[$key] ?? null;
                $state = 'unknown';
                $reason = $definition['availability'] !== 'available' ? 'adapter_unavailable' : ($snapshot['reason'] ?? 'accounting_unavailable');
                if ($snapshot['state'] === 'available' && $definition['availability'] === 'available' && $limit) {
                    if ($limit['kind'] === 'boolean') {
                        $state = $limit['value'] ? 'would_allow' : 'would_deny';
                        $reason = $limit['value'] ? null : 'feature_disabled';
                    } elseif ($limit['kind'] === 'enum') {
                        $reason = 'branding_field_policy_review_required';
                    } elseif (array_key_exists($key, $deltas) && $deltas[$key] === null) {
                        $reason = 'prospective_identity_requires_review';
                    } elseif ($measurement && $measurement['state'] === 'ready') {
                        $state = $limit['kind'] === 'unlimited' || $limit['value'] >= $measurement['count'] + ($deltas[$key] ?? 0) ? 'would_allow' : 'would_deny';
                        $reason = $state === 'would_deny' ? 'capacity_exceeded' : null;
                    }
                }
                $decisions[] = ['key' => $key, 'state' => $state, 'reasonCode' => $reason, 'currentUsage' => $measurement['count'] ?? null, 'occupied' => $measurement['count'] ?? null, 'reserved' => $measurement['invitation_slots'] ?? null, 'requestedDelta' => $deltas[$key] ?? 0, 'effectiveLimit' => $limit];
            }
            $result = ['workspaceId' => $workspaceId, 'operation' => $operation, 'mode' => 'observe', 'enforced' => false, 'authorization' => 'unchanged', 'decisions' => $decisions, 'revision' => $snapshot['subscription_revision'] ?? null, 'asOf' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'validUntil' => $snapshot['valid_until'], 'provenance' => $current ? 'workspace_mutex_current_reads' : 'pinned_request_snapshot', 'durationMicros' => (int) ((hrtime(true) - $started) / 1000)];
            $history = $request?->attributes->get('entitlement_shadow', []) ?? [];
            if (count($history) < 32) {
                $history[] = $result;
                $request?->attributes->set('entitlement_shadow', $history);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($e instanceof QueryException && in_array($e->errorInfo[1] ?? null, [1205, 1213], true) && DB::transactionLevel() > 0) {
                throw $e;
            }

            return ['workspaceId' => $workspaceId, 'operation' => $operation, 'mode' => 'observe', 'enforced' => false, 'authorization' => 'unchanged', 'decisions' => [], 'state' => 'unknown', 'reasonCode' => $e instanceof QueryException && ($e->errorInfo[1] ?? null) === 1020 ? 'current_snapshot_refresh_required' : 'shadow_adapter_unavailable'];
        }
    }
}
