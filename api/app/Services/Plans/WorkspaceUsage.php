<?php

namespace App\Services\Plans;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WorkspaceUsage
{
    public function __construct(private readonly EntitlementRegistry $registry, private readonly EffectiveParticipation $activity, private readonly InvitationOccupancy $invitations, private readonly ClientCountingIdentities $clients) {}

    public function measure(int $workspaceId, string $key, bool $current = false): array
    {
        $this->registry->definition($key);

        return $this->snapshot($workspaceId, $current, [$key])[$key];
    }

    /** Authoritative current facts, separate from limits and authorization. No writes, reservations or permits. */
    public function snapshot(int $workspaceId, bool $current = false, ?array $keys = null, bool $effective = false): array
    {
        abort_unless(DB::table('workspaces')->where('id', $workspaceId)->exists(), 404);
        $at = CarbonImmutable::now('UTC');
        $base = ['state' => 'ready', 'count' => null, 'as_of' => $at->format('Y-m-d\TH:i:s.u\Z'), 'reason' => null, 'reason_codes' => [], 'provenance' => 'authoritative_rows_v1'];
        $cycleReasons = $effective ? [] : $this->activity->reasons($workspaceId, $current);
        $result = [];
        $currentProjects = null;
        if ($current && ($keys === null || array_intersect($keys, ['projects.active.max', 'clients.max']))) {
            $projects = $effective ? $this->activity->projects($workspaceId) : DB::table('projects')->where('workspace_id', $workspaceId);
            $currentProjects = $projects->orderBy('id')->limit(20001)->lockForUpdate()->get(['id', 'status', 'deleted_at']);
        }
        $activeIds = $currentProjects?->filter(fn ($p) => $p->status === 'active' && $p->deleted_at === null)->pluck('id')->all();
        if ($keys === null || in_array('projects.active.max', $keys, true)) {
            $result['projects.active.max'] = array_replace($base, ['count' => $current ? ($currentProjects->count() > 20000 ? 20001 : count($activeIds)) : $this->activity->projects($workspaceId)->count()]);
        }
        if ($keys === null || in_array('agents.active.max', $keys, true)) {
            $agents = DB::table('external_agents')->where('workspace_id', $workspaceId);
            if ($current) {
                if ($effective) {
                    $this->activity->excludeHeld($agents, $workspaceId, 'external_agent', 'external_agents.id');
                }
                $currentAgents = $agents->orderBy('id')->limit(20001)->lockForUpdate()->get(['id', 'active', 'deleted_at']);
                $count = $currentAgents->count() > 20000 ? 20001 : $currentAgents->filter(fn ($a) => $a->active && ! $a->deleted_at)->count();
            } else {
                $count = $this->activity->excludeHeld($agents->where('active', true)->whereNull('deleted_at'), $workspaceId, 'external_agent', 'external_agents.id')->count();
            }
            $result['agents.active.max'] = array_replace($base, ['count' => $count]);
        }
        if ($keys === null || in_array('developers.max', $keys, true)) {
            $result['developers.max'] = array_replace($base, $this->invitations->measure($workspaceId, $at, $current, $effective));
        }
        if ($keys === null || in_array('clients.max', $keys, true)) {
            $rows = DB::table('project_clients as c')->whereIn('c.project_id', $current ? $activeIds : $this->activity->projects($workspaceId)->select('projects.id'))->where('c.active', true);
            if (! $current || $effective) {
                $rows = $this->activity->excludeHeld($rows, $workspaceId, 'project_client', 'c.id');
            }
            $identities = [];
            $missing = 0;
            $review = false;
            $scanned = $currentProjects?->count() > 20000 ? 20001 : 0;
            $rows->when($current, fn ($q) => $q->lockForUpdate())->orderBy('c.id')->select('c.*')->chunkById(200, function ($chunk) use ($current, $workspaceId, &$identities, &$missing, &$review, &$scanned): bool {
                $inputs = $this->clients->inputs($workspaceId, $chunk, $current);
                $observations = DB::table('entitlement_client_observations')->where('workspace_id', $workspaceId)->whereIn('project_client_id', $chunk->pluck('id'))->when($current, fn ($q) => $q->lockForUpdate())->get()->keyBy('project_client_id');
                $validIds = DB::table('entitlement_client_identities')->where('workspace_id', $workspaceId)->whereIn('id', $chunk->pluck('entitlement_identity_id')->filter())->when($current, fn ($q) => $q->lockForUpdate())->get(['id'])->pluck('id')->all();
                foreach ($chunk as $client) {
                    if (++$scanned > 20000) {
                        return false;
                    }
                    $observation = $observations->get($client->id);
                    if (! $client->entitlement_identity_id || ! in_array($client->entitlement_identity_id, $validIds)) {
                        $missing++;

                        continue;
                    }
                    $identities[$client->entitlement_identity_id] = true;
                    if (! $observation || (int) $observation->identity_id !== (int) $client->entitlement_identity_id || $observation->input_hash !== $inputs[$client->id]['hash']) {
                        $missing++;
                    } elseif ($observation->state !== 'ready') {
                        $review = true;
                    }
                }

                return true;
            }, 'c.id', 'id');
            $clientReasons = [];
            if ($missing) {
                $clientReasons[] = 'client_mapping_incomplete';
            }
            if ($review) {
                $clientReasons[] = 'client_accounting_review_required';
            }
            if ($scanned > 20000) {
                $clientReasons[] = 'client_scan_limit';
            }
            $result['clients.max'] = array_replace($base, ['state' => $clientReasons ? 'incomplete' : 'ready', 'count' => $missing || $scanned > 20000 ? null : count($identities), 'observed_count' => count($identities), 'missing_mappings' => $missing, 'reason_codes' => $clientReasons]);
        }
        if ($current) {
            foreach (['projects.active.max', 'agents.active.max'] as $key) {
                if (($result[$key]['count'] ?? 0) > 20000) {
                    $result[$key]['state'] = 'incomplete';
                    $result[$key]['count'] = null;
                    $result[$key]['reason_codes'] = ['growth_scan_limit'];
                }
            }
        }
        foreach ($this->registry->definitions() as $key => $d) {
            if ($keys !== null && ! in_array($key, $keys, true)) {
                continue;
            }
            if (isset($result[$key])) {
                if ($cycleReasons) {
                    $result[$key]['observed_count'] = $result[$key]['count'];
                    $result[$key]['count'] = null;
                    $result[$key]['state'] = 'incomplete';
                    $result[$key]['reason_codes'] = array_values(array_unique([...$result[$key]['reason_codes'], ...$cycleReasons]));
                }
                $result[$key]['reason'] = $result[$key]['reason_codes'][0] ?? null;
            } elseif ($d['availability'] !== 'available') {
                $result[$key] = array_replace($base, ['state' => 'unavailable', 'reason' => 'capacity_or_quota_adapter_unavailable', 'reason_codes' => ['capacity_or_quota_adapter_unavailable']]);
            } else {
                $result[$key] = array_replace($base, ['state' => 'unavailable', 'reason' => 'not_a_capacity_metric', 'reason_codes' => ['not_a_capacity_metric']]);
            }
        }

        return $result;
    }
}
