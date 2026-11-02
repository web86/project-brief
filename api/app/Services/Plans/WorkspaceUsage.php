<?php

namespace App\Services\Plans;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WorkspaceUsage
{
    public function __construct(private readonly EntitlementRegistry $registry, private readonly EffectiveParticipation $activity, private readonly InvitationOccupancy $invitations, private readonly ClientCountingIdentities $clients) {}

    public function measure(int $workspaceId, string $key): array
    {
        $this->registry->definition($key);

        return $this->snapshot($workspaceId)[$key];
    }

    /** Authoritative current facts, separate from limits and authorization. No writes, reservations or permits. */
    public function snapshot(int $workspaceId): array
    {
        abort_unless(DB::table('workspaces')->where('id', $workspaceId)->exists(), 404);
        $at = CarbonImmutable::now('UTC');
        $base = ['state' => 'ready', 'count' => null, 'as_of' => $at->format('Y-m-d\TH:i:s.u\Z'), 'reason' => null, 'reason_codes' => [], 'provenance' => 'authoritative_rows_v1'];
        $cycleReasons = $this->activity->reasons($workspaceId);
        $result = [];
        $result['projects.active.max'] = array_replace($base, ['count' => $this->activity->projects($workspaceId)->count()]);
        $result['agents.active.max'] = array_replace($base, ['count' => $this->activity->excludeHeld(DB::table('external_agents')->where('external_agents.workspace_id', $workspaceId)->where('external_agents.active', true)->whereNull('external_agents.deleted_at'), $workspaceId, 'external_agent', 'external_agents.id')->distinct()->count('external_agents.id')]);
        $result['developers.max'] = array_replace($base, $this->invitations->measure($workspaceId, $at));
        $rows = $this->activity->excludeHeld(DB::table('project_clients as c')->whereIn('c.project_id', $this->activity->projects($workspaceId)->select('projects.id'))->where('c.active', true), $workspaceId, 'project_client', 'c.id');
        $identities = [];
        $missing = 0;
        $review = false;
        $scanned = 0;
        $rows->orderBy('c.id')->select('c.*')->chunkById(200, function ($chunk) use ($workspaceId, &$identities, &$missing, &$review, &$scanned): bool {
            $inputs = $this->clients->inputs($workspaceId, $chunk);
            $observations = DB::table('entitlement_client_observations')->where('workspace_id', $workspaceId)->whereIn('project_client_id', $chunk->pluck('id'))->get()->keyBy('project_client_id');
            $validIds = DB::table('entitlement_client_identities')->where('workspace_id', $workspaceId)->whereIn('id', $chunk->pluck('entitlement_identity_id')->filter())->pluck('id')->all();
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
        if ($review || DB::table('entitlement_client_aliases')->where('workspace_id', $workspaceId)->where('state', 'ambiguous')->exists()) {
            $clientReasons[] = 'client_accounting_review_required';
        }
        if ($scanned > 20000) {
            $clientReasons[] = 'client_scan_limit';
        }
        $result['clients.max'] = array_replace($base, ['state' => $clientReasons ? 'incomplete' : 'ready', 'count' => $missing || $scanned > 20000 ? null : count($identities), 'observed_count' => count($identities), 'missing_mappings' => $missing, 'reason_codes' => $clientReasons]);
        foreach ($this->registry->definitions() as $key => $d) {
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
