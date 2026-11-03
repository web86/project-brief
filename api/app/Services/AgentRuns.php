<?php

namespace App\Services;

use App\Models\TaskAgentAssignment;
use App\Services\Plans\ExecutionBarrier;
use App\Services\Plans\ShadowGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentRuns
{
    public const ACTIVE = ['queued', 'running'];

    /** Caller holds the workspace lock and transaction. */
    public static function request(Request $request, TaskAgentAssignment $assignment, string $instruction, string $key, bool $emit = true, ?string $fingerprint = null): object
    {
        app(ShadowGuard::class)->observe($assignment->workspace_id, 'agent.run', []);
        $barrier = app(ExecutionBarrier::class);
        $epoch = $barrier->require($assignment->workspace_id, 'agent');
        $keyHash = hash('sha256', $key);
        $inputHash = $fingerprint ?? hash('sha256', $instruction);
        $previous = DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->where('key_hash', $keyHash)->first();
        if ($previous) {
            $barrier->require($assignment->workspace_id, 'agent', $previous);
            abort_unless(hash_equals($previous->request_hash, $inputHash), 409, 'Run request key already used.');

            return $previous;
        }
        if ($epoch !== null) {
            DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->whereIn('state', self::ACTIVE)
                ->where(fn ($query) => $query->whereNull('execution_epoch')->orWhere('execution_epoch', '<>', $epoch))
                ->update(['state' => 'cancelled', 'error_code' => 'subscription_execution_stale', 'completed_at' => now(), 'updated_at' => now()]);
        }
        abort_if(DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->whereIn('state', self::ACTIVE)->exists(), 409, 'An execution is already active.');
        $task = $assignment->task->fresh();
        $allHistory = self::conversation($assignment, agentApi: true);
        $history = array_slice($allHistory, -20);
        while (array_sum(array_map(fn ($message) => mb_strlen($message['text']), $history)) > 40000) {
            array_shift($history);
        }
        $context = ['agentInstruction' => $assignment->agent->instructions ?? '', 'projectInstruction' => $task->project->agent_instructions ?? '',
            'task' => ['id' => $task->uuid, 'projectId' => $task->project->uuid, 'title' => $task->title, 'description' => $task->description, 'expectedResult' => $task->expected_result, 'location' => $task->location], 'instruction' => $instruction, 'historyTruncated' => count($history) < count($allHistory) || count($allHistory) === 200, 'history' => $history];
        $json = json_encode($context, JSON_THROW_ON_ERROR);
        $id = DB::table('agent_runs')->insertGetId(['uuid' => (string) Str::uuid(), 'task_agent_assignment_id' => $assignment->id, 'initiated_by' => $request->user()?->id,
            'instruction' => $instruction, 'context' => $json, 'context_version' => hash('sha256', $json), 'key_hash' => $keyHash, 'request_hash' => $inputHash,
            'state' => 'queued', 'created_at' => now(), 'updated_at' => now()] + $barrier->receipt('agent', $epoch));
        if ($instruction !== '') {
            DB::table('agent_messages')->insert(['uuid' => (string) Str::uuid(), 'agent_run_id' => $id, 'user_id' => $request->user()?->id, 'text' => $instruction, 'created_at' => now()]);
        }
        $assignment->update(['state' => 'pending']);
        $run = DB::table('agent_runs')->find($id);
        if ($emit) {
            self::event($assignment, $run, 'agent.run.requested');
        }
        self::auditHuman($request, $assignment, $run, 'agent_run_requested', 'queued');

        return $run;
    }

    public static function auditHuman(Request $request, TaskAgentAssignment $assignment, object $run, string $action, string $state): void
    {
        $task = $assignment->task;
        $task->history()->create(['actor_type' => 'admin', 'actor_user_id' => $request->user()?->id, 'event_type' => $action,
            'meta' => ['teamOnly' => true, 'runId' => $run->uuid, 'state' => $state], 'created_at' => now()]);
        $task->touch();
        app(LiveSync::class)->record($assignment->workspace_id, $task->project_id, 'project');
    }

    public static function event(TaskAgentAssignment $assignment, object $run, string $type): void
    {
        app(WebhookEvents::class)->enqueue($assignment->workspace_id, $type, ['workspaceId' => $assignment->task->project->workspace->uuid,
            'projectId' => $assignment->task->project->uuid, 'taskId' => $assignment->task->uuid, 'agentId' => $assignment->agent->uuid,
            'assignmentId' => $assignment->uuid, 'runId' => $run->uuid]);
    }

    public static function cancel(TaskAgentAssignment $assignment, string $reason = 'agent_run_cancelled'): void
    {
        app(ShadowGuard::class)->observe($assignment->workspace_id, 'safe.reduce');
        $ids = DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->whereIn('state', self::ACTIVE)->pluck('uuid')->all();
        DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->whereIn('state', self::ACTIVE)
            ->update(['state' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        if ($ids !== []) {
            $events = DB::table('webhook_events')->where('workspace_id', $assignment->workspace_id)->where('body->assignmentId', $assignment->uuid)->whereIn('body->runId', $ids)->select('id');
            DB::table('webhook_deliveries')->whereIn('webhook_event_id', $events)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'error_code' => $reason, 'updated_at' => now()]);
        }
    }

    public static function data(object $run, bool $context = false, ?TaskAgentAssignment $assignment = null): array
    {
        return ['id' => $run->uuid, 'initiatedBy' => $run->initiated_by, 'assignmentId' => ($assignment ?? TaskAgentAssignment::findOrFail($run->task_agent_assignment_id))->uuid, 'instruction' => $run->instruction,
            'contextVersion' => $run->context_version, 'state' => $run->state, 'deliveredAt' => $run->delivered_at, 'startedAt' => $run->started_at,
            'completedAt' => $run->completed_at, 'errorCode' => $run->error_code, 'createdAt' => $run->created_at,
            ...($context ? ['context' => json_decode($run->context, true, flags: JSON_THROW_ON_ERROR)] : [])];
    }

    public static function conversation(TaskAgentAssignment $assignment, ?string $before = null, bool $agentApi = false): array
    {
        $query = DB::table('agent_messages')->join('agent_runs', 'agent_runs.id', '=', 'agent_messages.agent_run_id')
            ->leftJoin('comments', 'comments.id', '=', 'agent_messages.comment_id')->where('agent_runs.task_agent_assignment_id', $assignment->id);
        if ($agentApi) {
            $epoch = app(ExecutionBarrier::class)->require($assignment->workspace_id, 'agent');
            if ($epoch !== null) {
                $query->where('agent_runs.execution_feature_group', 'agent')->where('agent_runs.execution_epoch', $epoch);
            }
        }
        if ($before !== null) {
            $id = (clone $query)->where('agent_messages.uuid', $before)->value('agent_messages.id');
            abort_unless($id, 404);
            $query->where('agent_messages.id', '<', $id);
        }

        return $query->orderByDesc('agent_messages.id')->limit(200)
            ->get(['agent_messages.uuid', 'agent_messages.text', 'agent_messages.external_agent_id', 'agent_messages.created_at', 'agent_runs.uuid as run_uuid', 'agent_runs.state as run_state', 'comments.uuid as comment_uuid'])
            ->map(fn ($message) => ['id' => $message->uuid, 'runId' => $message->run_uuid, 'state' => $message->run_state, 'commentId' => $message->comment_uuid, 'author' => $message->external_agent_id ? 'agent' : 'human', 'text' => $message->text, 'createdAt' => $message->created_at])->reverse()->values()->all();
    }
}
