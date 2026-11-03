<?php

namespace App\Services\Plans;

use App\Models\Project;
use App\Services\DeveloperInvitations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EffectiveParticipation
{
    /** The shared read predicate for preview and future capacity writers. No overlay is created here. */
    public function projects(int $workspaceId): Builder
    {
        return $this->excludeHeld(DB::table('projects')->where('projects.workspace_id', $workspaceId)->whereNull('projects.deleted_at')->where('projects.status', 'active'), $workspaceId, 'project', 'projects.id');
    }

    public function visibleProjects(int $workspaceId): EloquentBuilder
    {
        $query = Project::where('workspace_id', $workspaceId);
        $this->excludeHeld($query->getQuery(), $workspaceId, 'project', 'projects.id');

        return $query;
    }

    public function clientAvailable(int $workspaceId, int $projectId, int $clientId): bool
    {
        $client = DB::table('project_clients')->where('id', $clientId)->where('project_id', $projectId)->where('active', true);

        return ! $this->boundaryDue($workspaceId) && $this->projects($workspaceId)->where('projects.id', $projectId)->exists()
            && $this->excludeHeld($client, $workspaceId, 'project_client', 'project_clients.id')->exists();
    }

    public function boundaryDue(int $workspaceId): bool
    {
        return DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)
            ->where('next_boundary_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))
            ->where(fn ($query) => $query->where('enforcement_mode', 'enforce')->orWhereExists(
                DB::table('workspace_execution_epochs')->where('workspace_id', $workspaceId)
            ))->exists();
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
        abort_if($this->boundaryDue($workspaceId), 409, 'subscription_boundary_pending');
        $project = DB::table('projects')->where('workspace_id', $workspaceId)->where('id', $projectId);
        abort_unless($this->excludeHeld($project, $workspaceId, 'project', 'projects.id')->exists(), 403, 'subscription_project_held');
    }

    /** Capacity adapters must review new allocations while a selection is in force. */
    public function requireGrowthAvailable(int $workspaceId): void
    {
        abort_if($this->boundaryDue($workspaceId), 409, 'subscription_boundary_pending');
        abort_if(DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)
            ->where('state', 'open')->exists(), 403, 'subscription_selection_required');
    }

    public function requireInvitationSeat(int $workspaceId, int $invitationId): void
    {
        abort_if($this->boundaryDue($workspaceId), 409, 'subscription_boundary_pending');
        $cycle = DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')->orderByDesc('id')->sharedLock()->first();
        if (! $cycle) {
            return;
        }

        $email = DB::table('developer_invitations')->where('workspace_id', $workspaceId)->where('id', $invitationId)->value('email');
        $user = DB::table('users')->whereRaw('LOWER(TRIM(email)) = ?', [DeveloperInvitations::normalizeEmail((string) $email)])->value('id');
        $identity = app(InvitationOccupancy::class)->identity((string) $email, $user);
        abort_unless(DB::table('entitlement_capacity_reservations')->where('workspace_id', $workspaceId)
            ->where('developer_invitation_id', $invitationId)->where('key', 'developers.max')->where('state', 'held')
            ->where('restored_cycle_id', $cycle->id)->where('identity_hash', $identity)->where('expires_at', '>', now('UTC'))->exists(), 403, 'subscription_selection_required');
    }

    /** Open-cycle high-watermark semantics need the future executor; never promise a ready count. */
    public function reasons(int $workspaceId, bool $current = false): array
    {
        return DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')->when($current, fn ($q) => $q->sharedLock())->first(['id']) !== null ? ['participation_cycle_adapter_required'] : [];
    }
}
