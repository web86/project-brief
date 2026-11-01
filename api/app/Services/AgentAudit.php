<?php

namespace App\Services;

use App\Models\TaskAgentAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentAudit
{
    public static function request(Request $request, int $status): void
    {
        $token = $request->attributes->get('integration_token');
        $agent = $request->attributes->get('integration_agent');
        $claimed = $request->header(AgentCredentials::HEADER, $agent?->uuid);
        $assignment = $request->attributes->get('agent_assignment');
        $task = $assignment?->task ?? $request->attributes->get('agent_read_task');
        $project = $task?->project ?? $request->attributes->get('agent_read_project');
        DB::table('agent_operations')->insert(['workspace_id' => $token->workspace_id, 'external_agent_id' => $agent?->id, 'integration_token_id' => $token->id, 'claimed_agent_uuid' => is_string($claimed) && Str::isUuid($claimed) ? $claimed : null,
            'project_uuid' => $project?->uuid, 'task_uuid' => $task?->uuid, 'assignment_uuid' => $assignment?->uuid,
            'operation' => $request->method().' /'.($request->route()?->uri() ?? 'api/v1/{unmatched}'), 'http_status' => $status, 'created_at' => now()]);
        $success = $status >= 200 && $status < 300;
        if ($agent) {
            DB::table('external_agents')->where('id', $agent->id)->increment($success ? 'successful_requests' : 'failed_requests', 1, $success ? ['last_success_at' => now()] : []);
        }
    }

    public static function task(Request $request, TaskAgentAssignment $assignment, string $operation, array $meta = []): void
    {
        $agent = $assignment->agent;
        $assignment->task->history()->create(['actor_type' => 'agent', 'event_type' => $operation,
            'meta' => ['agentId' => $agent->uuid, 'agentName' => $agent->name, 'assignmentId' => $assignment->uuid,
                ...(in_array($operation, ['agent_reports', 'agent_comments', 'agent_attachments', 'agent_messages'], true) ? ['teamOnly' => true] : []), 'credentialId' => $request->attributes->get('integration_token')?->uuid, ...$meta], 'created_at' => now()]);
        $assignment->task->touch();
        app(LiveSync::class)->record($agent->workspace_id, $assignment->task->project_id, 'project');
    }
}
