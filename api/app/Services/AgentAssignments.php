<?php

namespace App\Services;

use App\Models\ExternalAgent;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskAgentAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentAssignments
{
    public static function event(TaskAgentAssignment $row, string $type): void
    {
        $task = $row->task;
        app(WebhookEvents::class)->enqueue($row->workspace_id, $type, ['workspaceId' => $task->project->workspace->uuid, 'projectId' => $task->project->uuid,
            'taskId' => $task->uuid, 'agentId' => $row->agent->uuid, 'assignmentId' => $row->uuid, 'state' => $row->state]);
    }

    public static function revoke(Request $request, TaskAgentAssignment $row, string $type = 'agent.task.unassigned'): void
    {
        $row->update(['revoked_at' => now(), 'state' => 'cancelled']);
        self::event($row, $type);
        AgentAudit::task($request, $row, 'agent_unassigned');
    }

    public static function removeProject(Request $request, ExternalAgent $agent, Project $project): void
    {
        foreach (TaskAgentAssignment::where('external_agent_id', $agent->id)->whereNull('revoked_at')->whereHas('task', fn ($q) => $q->withTrashed()->where('project_id', $project->id))->get() as $row) {
            if ($row->task) {
                self::revoke($request, $row);
            } else {
                $row->update(['revoked_at' => now(), 'state' => 'cancelled']);
            }
        }
        ProjectMember::where('workspace_id', $agent->workspace_id)->where('project_id', $project->id)->where('external_agent_id', $agent->id)->delete();
    }

    public static function taskState(Task $task): array
    {
        return $task->agentAssignments->map(fn ($row) => ['id' => $row->uuid, 'agent' => $row->agent->publicState(), 'state' => $row->state,
            'reports' => DB::table('agent_reports')->where('task_agent_assignment_id', $row->id)->orderBy('id')->get()->map(fn ($r) => ['id' => $r->uuid, 'state' => $r->state, 'pullRequestUrl' => $r->pull_request_url, 'resultUrl' => $r->result_url, 'createdAt' => $r->created_at])])->all();
    }
}
