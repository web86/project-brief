<?php

namespace App\Services;

use App\Models\ExternalAgent;
use App\Models\TaskAgentAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AgentAccess
{
    public const SCOPES = ['agent:profile', 'agent:tasks:read', 'agent:tasks:report', 'agent:comments:create', 'agent:attachments:create'];

    public static function identity(Request $request): ExternalAgent
    {
        $token = $request->attributes->get('integration_token');
        abort_unless($token?->external_agent_id, 403);

        return ExternalAgent::where('workspace_id', $token->workspace_id)->whereKey($token->external_agent_id)->where('active', true)->firstOrFail();
    }

    public static function scope(Request $request, string $scope): ExternalAgent
    {
        IntegrationReadAccess::scope($request, $scope);
        $request->attributes->set('integration_required_scope', $scope);

        return self::identity($request);
    }

    public static function assignments(ExternalAgent $agent): Builder
    {
        return TaskAgentAssignment::where('task_agent_assignments.workspace_id', $agent->workspace_id)->where('external_agent_id', $agent->id)->whereNull('revoked_at')
            ->whereHas('task', fn ($q) => $q->whereHas('project', fn ($p) => $p->where('workspace_id', $agent->workspace_id)->where('status', 'active')
                ->whereExists(fn ($m) => $m->selectRaw('1')->from('project_members')->whereColumn('project_members.project_id', 'projects.id')->where('project_members.workspace_id', $agent->workspace_id)->where('project_members.external_agent_id', $agent->id))
                ->whereExists(fn ($g) => $g->selectRaw('1')->from('agent_project_grants')->whereColumn('agent_project_grants.project_id', 'projects.id')->where('agent_project_grants.workspace_id', $agent->workspace_id)->where('agent_project_grants.external_agent_id', $agent->id))));
    }

    public static function assignment(Request $request, string $taskUuid): TaskAgentAssignment
    {
        $row = self::assignments(self::identity($request))->whereHas('task', fn ($q) => $q->where('uuid', $taskUuid))->firstOrFail();
        $request->attributes->set('agent_assignment', $row);

        return $row;
    }

    public static function deliveryAllowed(object $endpoint, array $payload): bool
    {
        $agent = ExternalAgent::where('workspace_id', $endpoint->workspace_id)->whereKey($endpoint->external_agent_id)->where('active', true)->first();
        if (! $agent || ($payload['agentId'] ?? null) !== $agent->uuid) {
            return false;
        }
        $assignment = self::assignments($agent)->where('uuid', $payload['assignmentId'] ?? '')->whereHas('task', fn ($q) => $q->where('uuid', $payload['taskId'] ?? ''))->first();

        return $assignment !== null;
    }

    public static function fields(Request $request, array $allowed): void
    {
        abort_if(array_diff(array_keys($request->all()), $allowed), 422);
    }
}
