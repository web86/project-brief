<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\TaskAgentAssignment;
use App\Rules\SafeAttachment;
use App\Services\AgentAccess;
use App\Services\AgentAudit;
use App\Services\AgentPermissions;
use App\Services\AgentRuns;
use App\Services\AttachmentStorage;
use App\Services\IntegrationWrites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgentApiController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $agent = AgentAccess::scope($request, 'agent:profile');

        return response()->json(['data' => [...$agent->publicState(), 'workspaceId' => DB::table('workspaces')->where('id', $agent->workspace_id)->value('uuid'),
            'instructions' => $agent->instructions ?? '', 'permissions' => AgentPermissions::normalize($agent->permissions), 'scopes' => json_decode($request->attributes->get('integration_token')->scopes, true),
            'projectIds' => AgentAccess::projects($agent)->orderBy('uuid')->pluck('uuid')]]);
    }

    private function data(TaskAgentAssignment $row, bool $details = false): array
    {
        $task = $row->task;

        return ['id' => $task->uuid, 'assignmentId' => $row->uuid, 'projectId' => $task->project->uuid, 'title' => $task->title, 'taskStatus' => $task->status,
            'executionState' => $row->state, 'updatedAt' => $task->updated_at->toISOString(), ...($details ? ['description' => $task->description, 'expectedResult' => $task->expected_result, 'location' => $task->location, 'priority' => $task->priority] : [])];
    }

    public function index(Request $request): JsonResponse
    {
        $agent = AgentAccess::scope($request, 'agent:tasks:read');
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $page = AgentAccess::assignments($agent)->with('task.project')->orderBy('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => $this->data($row)), 'meta' => ['page' => $page->currentPage(), 'perPage' => 25, 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        AgentAccess::scope($request, 'agent:tasks:read');

        return response()->json(['data' => $this->data(AgentAccess::assignment($request, $task), true)]);
    }

    public function write(Request $request, string $task, string $operation): JsonResponse
    {
        abort_if($operation !== 'attachments' && ! $request->isJson(), 415);
        abort_if($operation !== 'attachments' && strlen($request->getContent()) > 32768, 413);
        $scope = ['reports' => 'agent:tasks:report', 'comments' => 'agent:comments:create', 'attachments' => 'agent:attachments:create', 'messages' => 'agent:messages:create'][$operation] ?? null;
        abort_unless($scope, 404);
        AgentAccess::scope($request, $scope);
        AgentAccess::assignment($request, $task);
        $allowed = match ($operation) {
            'reports' => ['runId', 'assignmentId', 'state', 'summary', 'pullRequestUrl', 'resultUrl', 'errorCode'],
            'messages', 'comments' => ['runId', 'assignmentId', 'text'],
            default => ['runId', 'assignmentId', 'text', 'attachments'],
        };
        AgentAccess::fields($request, $allowed);
        $rules = ['assignmentId' => ['required', 'uuid'], 'runId' => ['sometimes', 'uuid']];
        if ($operation === 'reports') {
            $rules += ['state' => ['required', Rule::in(['pending', 'running', 'submitted', 'failed', 'cancelled'])], 'summary' => ['required', 'string', 'max:5000', 'regex:/\S/u'], 'errorCode' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/D'], 'pullRequestUrl' => ['nullable', 'string', 'max:2048', 'url:https'], 'resultUrl' => ['nullable', 'string', 'max:2048', 'url:https']];
        } elseif (in_array($operation, ['comments', 'messages'], true)) {
            $rules += ['text' => ['required', 'string', 'max:5000', 'regex:/\S/u']];
        } else {
            $rules += ['text' => ['nullable', 'string', 'max:5000'], 'attachments' => ['required', 'array', 'min:1', 'max:5'], 'attachments.*' => ['required', 'file', 'max:20480', new SafeAttachment]];
        }
        if ($operation === 'messages') {
            $rules['runId'] = ['required', 'uuid'];
        }
        $data = $request->validate($rules);
        foreach (['pullRequestUrl', 'resultUrl'] as $field) {
            if (! empty($data[$field])) {
                $url = parse_url($data[$field]);
                abort_if(isset($url['user']) || isset($url['pass']) || preg_match('/[\x00-\x20\x7f]/', $data[$field]), 422);
            }
        }
        $files = $request->file('attachments', []);
        abort_if(array_sum(array_map(fn ($f) => $f->getSize(), $files)) > 40 * 1024 * 1024, 422);
        unset($data['attachments']);
        $canonical = [$operation, $data, array_map(fn ($f) => [$f->getClientOriginalName(), hash_file('sha256', $f->getPathname())], $files)];

        return AttachmentStorage::atomic(function (array &$paths) use ($request, $task, $operation, $data, $canonical): JsonResponse {
            return app(IntegrationWrites::class)->run($request, function () use ($request, $task, $operation, $data, &$paths): array {
                $row = AgentAccess::assignment($request, $task);
                abort_unless(hash_equals($row->uuid, $data['assignmentId']), 409);
                $run = null;
                if (isset($data['runId'])) {
                    $run = DB::table('agent_runs')->where('task_agent_assignment_id', $row->id)->where('uuid', $data['runId'])->firstOrFail();
                    abort_unless(in_array($run->state, AgentRuns::ACTIVE, true), 409, 'Execution is no longer active.');
                } elseif ($operation === 'reports') {
                    $run = DB::table('agent_runs')->where('task_agent_assignment_id', $row->id)->whereIn('state', AgentRuns::ACTIVE)->orderBy('id')->first();
                    abort_if(! $run && DB::table('agent_runs')->where('task_agent_assignment_id', $row->id)->exists(), 409, 'Execution is no longer active.');
                    abort_if($run && $run->key_hash !== hash('sha256', 'initial-'.$row->uuid), 409, 'runId is required for follow-up executions.');
                }
                abort_if($run && $operation === 'reports' && $run->state === 'running' && $data['state'] === 'pending', 409);
                $comment = Comment::create(['project_id' => $row->task->project_id, 'task_id' => $row->task_id, 'external_agent_id' => $row->external_agent_id,
                    'author_type' => 'agent', 'team_only' => true, 'text' => trim($data['summary'] ?? $data['text'] ?? '')]);
                if ($operation === 'attachments') {
                    AttachmentStorage::storeCommentFiles($request, $comment, $row->task, $paths);
                }
                $uuid = $comment->uuid;
                if ($operation === 'reports') {
                    $uuid = (string) Str::uuid();
                    DB::table('agent_reports')->insert(['uuid' => $uuid, 'agent_run_id' => $run?->id, 'task_agent_assignment_id' => $row->id, 'comment_id' => $comment->id, 'state' => $data['state'], 'pull_request_url' => $data['pullRequestUrl'] ?? null, 'result_url' => $data['resultUrl'] ?? null, 'created_at' => now()]);
                    $row->update(['state' => $data['state']]);
                }
                if ($run) {
                    $terminal = $operation === 'reports' && in_array($data['state'], ['submitted', 'failed', 'cancelled'], true);
                    if ($operation === 'reports') {
                        DB::table('agent_runs')->where('id', $run->id)->update(['state' => $data['state'] === 'pending' ? 'queued' : $data['state'], 'result_comment_id' => $comment->id,
                            'error_code' => $data['state'] === 'failed' ? ($data['errorCode'] ?? 'executor_failed') : null, 'started_at' => $data['state'] === 'running' ? ($run->started_at ?? now()) : $run->started_at, 'completed_at' => $terminal ? now() : null, 'updated_at' => now()]);
                    }
                    DB::table('agent_messages')->insert(['uuid' => (string) Str::uuid(), 'agent_run_id' => $run->id, 'external_agent_id' => $row->external_agent_id, 'comment_id' => $comment->id, 'text' => $comment->text, 'created_at' => now()]);
                    AgentRuns::event($row, $run, 'agent.message.created');
                }
                AgentAudit::task($request, $row, 'agent_'.$operation, ['commentId' => $comment->id, 'state' => $row->state]);

                return [['data' => ['id' => $uuid, 'assignmentId' => $row->uuid, 'commentId' => $comment->uuid, 'executionState' => $row->state,
                    'attachments' => $comment->attachments()->withoutGlobalScope('public_channel')->get()->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->original_name, 'size' => $f->size])->all()]], 201];
            }, $canonical);
        });
    }
}
