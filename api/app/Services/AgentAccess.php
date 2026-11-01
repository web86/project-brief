<?php

namespace App\Services;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentAccess
{
    public const EVENTS = ['agent.task.assigned', 'agent.task.updated', 'agent.task.unassigned', 'agent.task.cancelled', 'agent.run.requested', 'agent.message.created', 'agent.run.cancelled'];

    public const LEGACY_SCOPES = ['agent:profile', 'agent:tasks:read', 'agent:tasks:report', 'agent:comments:create', 'agent:attachments:create'];

    public const SCOPES = [...self::LEGACY_SCOPES, 'agent:projects:tasks:read', 'agent:comments:read', 'agent:discussions:read', 'agent:team-chat:read', 'agent:attachments:read', 'agent:runs:read', 'agent:runs:write', 'agent:messages:read', 'agent:messages:create'];

    public static function identity(Request $request): ExternalAgent
    {
        return AgentCredentials::identity($request, $request->attributes->get('integration_token'));
    }

    public static function scope(Request $request, string $scope): ExternalAgent
    {
        IntegrationReadAccess::scope($request, $scope);
        $request->attributes->set('integration_required_scope', $scope);

        $agent = self::identity($request);
        abort_unless(in_array($scope, AgentPermissions::scopes($agent), true), 403);

        return $agent;
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

    public static function projects(ExternalAgent $agent): Builder
    {
        return Project::where('workspace_id', $agent->workspace_id)->where('status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('agent_project_grants')->whereColumn('agent_project_grants.project_id', 'projects.id')->where('agent_project_grants.workspace_id', $agent->workspace_id)->where('agent_project_grants.external_agent_id', $agent->id))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('project_members')->whereColumn('project_members.project_id', 'projects.id')->where('project_members.workspace_id', $agent->workspace_id)->where('project_members.external_agent_id', $agent->id));
    }

    public static function tokenAllows(Request $request, string $scope): bool
    {
        return in_array($scope, json_decode($request->attributes->get('integration_token')->scopes, true), true) && in_array($scope, AgentPermissions::scopes(self::identity($request)), true);
    }

    public static function accessibleTask(Request $request, string $uuid): Task
    {
        $agent = self::identity($request);
        $task = Task::where('uuid', $uuid)->whereIn('project_id', self::projects($agent)->select('projects.id'))->firstOrFail();
        $projectRead = self::tokenAllows($request, 'agent:projects:tasks:read');
        $assignedRead = self::tokenAllows($request, 'agent:tasks:read') && self::assignments($agent)->where('task_id', $task->id)->exists();
        abort_unless($projectRead || $assignedRead, 404);
        $request->attributes->set('agent_read_task', $task);

        return $task;
    }

    public static function deliveryAllowed(object $endpoint, array $payload, ?string $type = null): bool
    {
        $agent = ExternalAgent::where('workspace_id', $endpoint->workspace_id)->whereKey($endpoint->external_agent_id)->where('active', true)->first();
        if (! $agent || ($payload['agentId'] ?? null) !== $agent->uuid) {
            return false;
        }
        if (($type ?? $payload['type'] ?? null) === 'webhook.test') {
            return true;
        }
        $assignment = self::assignments($agent)->where('uuid', $payload['assignmentId'] ?? '')->whereHas('task', fn ($q) => $q->where('uuid', $payload['taskId'] ?? ''))->first();

        if (! $assignment) {
            return false;
        }
        if (isset($payload['runId'])) {
            $run = DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->where('uuid', $payload['runId'])->first();
            if (! $run) {
                return false;
            }
            $type ??= $payload['type'] ?? null;
            if ($type === 'agent.run.requested' && ! in_array($run->state, AgentRuns::ACTIVE, true)) {
                return false;
            }
            if ($run->state === 'cancelled' && in_array($type, ['agent.run.requested', 'agent.message.created'], true)) {
                return false;
            }
        }

        return true;
    }

    public static function fields(Request $request, array $allowed): void
    {
        abort_if(array_diff(array_keys($request->all()), $allowed), 422);
    }
}
