<?php

namespace App\Services\Plans;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EffectiveParticipation
{
    /** The shared read predicate for preview and future capacity writers. No overlay is created here. */
    public function projects(int $workspaceId): Builder
    {
        return $this->excludeHeld(DB::table('projects')->where('projects.workspace_id', $workspaceId)->whereNull('projects.deleted_at')->where('projects.status', 'active'), $workspaceId, 'project', 'projects.id');
    }

    public function excludeHeld(Builder $query, int $workspaceId, string $kind, string $idColumn): Builder
    {
        return $query->whereNotExists(function (Builder $held) use ($workspaceId, $kind, $idColumn): void {
            $held->selectRaw('1')->from('workspace_entitlement_resource_states as held')
                ->join('workspace_suspension_cycles as cycle', 'cycle.id', '=', 'held.cycle_id')
                ->where('held.workspace_id', $workspaceId)->where('cycle.workspace_id', $workspaceId)
                ->where('cycle.state', 'open')->where('held.resource_kind', $kind)->where('held.state', 'held')->whereColumn('held.resource_id', $idColumn);
        })->whereNotExists(function (Builder $cycle) use ($workspaceId, $kind, $idColumn): void {
            $cycle->selectRaw('1')->from('workspace_suspension_cycles as marker')
                ->where('marker.workspace_id', $workspaceId)->where('marker.state', 'open')
                ->whereRaw('CAST(JSON_EXTRACT(marker.resource_high_watermarks_json, ?) AS DECIMAL(20,0)) >= '.$cycle->getGrammar()->wrap($idColumn), ['$.'.$kind])
                ->whereNotExists(function (Builder $release) use ($kind, $idColumn): void {
                    $release->selectRaw('1')->from('workspace_entitlement_resource_states as released')
                        ->whereColumn('released.workspace_id', 'marker.workspace_id')
                        ->whereColumn('released.cycle_id', 'marker.id')->whereColumn('released.resource_id', $idColumn)
                        ->where('released.resource_kind', $kind)->where('released.state', 'released');
                });
        });
    }

    public function requireProjectWritable(int $workspaceId, int $projectId): void
    {
        $project = DB::table('projects')->where('workspace_id', $workspaceId)->where('id', $projectId);
        abort_unless($this->excludeHeld($project, $workspaceId, 'project', 'projects.id')->exists(), 403, 'subscription_project_held');
    }

    /** Capacity adapters must review new allocations while a selection is in force. */
    public function requireGrowthAvailable(int $workspaceId): void
    {
        abort_if(DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)
            ->where('state', 'open')->exists(), 403, 'subscription_selection_required');
    }

    /** Open-cycle high-watermark semantics need the future executor; never promise a ready count. */
    public function reasons(int $workspaceId, bool $current = false): array
    {
        return DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')->when($current, fn ($q) => $q->sharedLock())->first(['id']) !== null ? ['participation_cycle_adapter_required'] : [];
    }
}
