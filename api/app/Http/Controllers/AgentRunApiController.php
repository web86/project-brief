<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\AgentAccess;
use App\Services\AgentAudit;
use App\Services\AgentChat;
use App\Services\AgentRuns;
use App\Services\IntegrationWrites;
use App\Services\Plans\ExecutionBarrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentRunApiController extends Controller
{
    public function index(Request $request, string $task): JsonResponse
    {
        AgentAccess::scope($request, 'agent:runs:read');
        $assignment = AgentAccess::assignment($request, $task);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $epoch = app(ExecutionBarrier::class)->require($assignment->workspace_id, 'agent');
        $page = DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)
            ->when($epoch !== null, fn ($q) => $q->where('execution_feature_group', 'agent')->where('execution_epoch', $epoch))
            ->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map(fn ($run) => AgentRuns::data($run, false, $assignment)), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage()]]);
    }

    public function show(Request $request, string $task, string $run): JsonResponse
    {
        AgentAccess::scope($request, 'agent:runs:read');
        AgentAccess::scope($request, 'agent:messages:read');
        $assignment = AgentAccess::assignment($request, $task);
        $row = DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->where('uuid', $run)->firstOrFail();
        app(ExecutionBarrier::class)->require($assignment->workspace_id, 'agent', $row);

        return response()->json(['data' => AgentRuns::data($row, true, $assignment)]);
    }

    public function messages(Request $request, string $task): JsonResponse
    {
        AgentAccess::scope($request, 'agent:messages:read');

        $input = $request->validate(['before' => ['sometimes', 'uuid']]);
        $files = AgentAccess::tokenAllows($request, 'agent:attachments:read');
        $messages = AgentChat::messages(AgentAccess::assignment($request, $task), $input['before'] ?? null, $files, true);

        return response()->json(['data' => $messages, 'nextCursor' => count($messages) === 200 ? $messages[0]['id'] : null]);
    }

    public function download(Request $request, string $task, string $run, string $attachment): StreamedResponse
    {
        AgentAccess::scope($request, 'agent:attachments:read');
        AgentAccess::scope($request, 'agent:messages:read');
        $assignment = AgentAccess::assignment($request, $task);
        $row = DB::table('agent_runs')->where('uuid', $run)->where('task_agent_assignment_id', $assignment->id)->firstOrFail();
        app(ExecutionBarrier::class)->require($assignment->workspace_id, 'agent', $row);
        $comments = DB::table('agent_messages')->where('agent_run_id', $row->id)->whereNotNull('comment_id')->select('comment_id');
        $file = Attachment::withoutGlobalScope('public_channel')->where('uuid', $attachment)->where('task_id', $assignment->task_id)->whereIn('comment_id', $comments)->firstOrFail();
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer']);
    }

    public function start(Request $request, string $task, string $run): JsonResponse
    {
        AgentAccess::scope($request, 'agent:runs:write');
        AgentAccess::assignment($request, $task);
        AgentAccess::fields($request, ['assignmentId', 'runId']);
        $input = $request->validate(['assignmentId' => ['required', 'uuid'], 'runId' => ['required', 'uuid']]);
        abort_unless($input['runId'] === $run, 409);

        return app(IntegrationWrites::class)->run($request, function () use ($request, $task, $run): array {
            $assignment = AgentAccess::assignment($request, $task);
            $row = DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->where('uuid', $run)->firstOrFail();
            app(ExecutionBarrier::class)->require($assignment->workspace_id, 'agent', $row);
            abort_unless($row->state === 'queued', 409, 'Execution is already claimed or no longer active.');
            if ($row->state === 'queued') {
                DB::table('agent_runs')->where('id', $row->id)->update(['state' => 'running', 'started_at' => now(), 'updated_at' => now()]);
                $assignment->update(['state' => 'running']);
                AgentAudit::task($request, $assignment, 'agent_run_started', ['teamOnly' => true, 'runId' => $row->uuid, 'state' => 'running']);
            }

            return [['data' => AgentRuns::data(DB::table('agent_runs')->find($row->id), false, $assignment)], 200];
        });
    }
}
