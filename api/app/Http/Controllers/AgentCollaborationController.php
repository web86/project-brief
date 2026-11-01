<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Project;
use App\Models\TaskAgentAssignment;
use App\Rules\SafeAttachment;
use App\Services\AccountSecurity;
use App\Services\AgentAccess;
use App\Services\AgentChat;
use App\Services\AgentRuns;
use App\Services\AttachmentStorage;
use App\Services\IntegrationWrites;
use App\Services\ProjectAccess;
use App\Services\ProjectPermissions;
use App\Services\TaskAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentCollaborationController extends Controller
{
    public function projectInstructions(Request $request, Project $project): JsonResponse
    {
        if ($request->isMethod('PATCH')) {
            AgentAccess::fields($request, ['instructions']);
            $input = $request->validate(['instructions' => ['nullable', 'string', 'max:10000']]);
            DB::transaction(function () use ($request, $project, $input): void {
                IntegrationWrites::lockWorkspace($project->workspace_id);
                ProjectAccess::project($request, $project->uuid);
                ProjectPermissions::authorize($request, $project, 'manage_project_settings', true);
                app(AccountSecurity::class)->requireFresh($request);
                $project->forceFill(['agent_instructions' => $input['instructions'] ?? null])->save();
                app(AccountSecurity::class)->audit($request->user(), 'project_agent_instructions:'.$project->id);
            }, 3);
        }

        return response()->json(['instructions' => $project->fresh()->agent_instructions ?? ''])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, string $task): JsonResponse
    {
        $model = TaskAccess::task($request, $task);
        $assignments = TaskAgentAssignment::with('agent')->where('task_id', $model->id)->orderBy('id')->get();

        return response()->json(['data' => $assignments->map(function ($assignment): array {
            $messages = AgentChat::messages($assignment);

            return ['id' => $assignment->uuid, 'agent' => $assignment->agent->publicState(), 'revokedAt' => $assignment->revoked_at,
                'runCount' => DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->count(), 'historyPage' => 1,
                'messages' => $messages, 'messagesCursor' => count($messages) === 200 ? $messages[0]['id'] : null,
                'runs' => DB::table('agent_runs')->where('task_agent_assignment_id', $assignment->id)->orderByDesc('id')->limit(25)->get()->map(fn ($run) => AgentRuns::data($run, false, $assignment))];
        }), 'legacyMessages' => AgentChat::legacy($model)])->header('Cache-Control', 'no-store');
    }

    public function messages(Request $request, string $task, string $assignment): JsonResponse
    {
        $model = TaskAccess::task($request, $task);
        $row = TaskAgentAssignment::where('task_id', $model->id)->where('uuid', $assignment)->firstOrFail();
        $input = $request->validate(['before' => ['sometimes', 'uuid']]);
        $messages = AgentChat::messages($row, $input['before'] ?? null);

        return response()->json(['data' => $messages, 'nextCursor' => count($messages) === 200 ? $messages[0]['id'] : null])->header('Cache-Control', 'no-store');
    }

    public function legacy(Request $request, string $task): JsonResponse
    {
        $input = $request->validate(['before' => ['sometimes', 'uuid']]);

        return response()->json(AgentChat::legacy(TaskAccess::task($request, $task), $input['before'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function history(Request $request, string $task, string $assignment): JsonResponse
    {
        $model = TaskAccess::task($request, $task);
        $row = TaskAgentAssignment::where('task_id', $model->id)->where('uuid', $assignment)->firstOrFail();
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $page = DB::table('agent_runs')->where('task_agent_assignment_id', $row->id)->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map(fn ($run) => AgentRuns::data($run, false, $row)), 'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $task, string $assignment): JsonResponse
    {
        AgentAccess::fields($request, ['instruction', 'submissionId', 'attachments']);
        $input = $request->validate(['instruction' => ['required_without:attachments', 'nullable', 'string', 'max:5000', 'regex:/\S/u'], 'submissionId' => ['required', 'uuid'], 'attachments' => ['sometimes', 'array', 'min:1', 'max:5'], 'attachments.*' => ['required', 'file', 'max:20480', new SafeAttachment]]);
        abort_if(array_sum(array_map(fn ($file) => $file->getSize(), $request->file('attachments', []))) > 40 * 1024 * 1024, 422);
        $project = TaskAccess::task($request, $task)->project;

        return AttachmentStorage::atomic(function (array &$paths) use ($request, $task, $assignment, $input, $project): JsonResponse {
            IntegrationWrites::lockWorkspace($project->workspace_id);
            $model = TaskAccess::task($request, $task, true);
            ProjectPermissions::authorize($request, $project, 'manage_structure', true);
            $row = TaskAgentAssignment::where('task_id', $model->id)->where('workspace_id', $project->workspace_id)->where('uuid', $assignment)->whereNull('revoked_at')->firstOrFail();
            abort_unless($project->fresh()->status === 'active' && $row->agent->active && AgentAccess::assignments($row->agent)->whereKey($row->id)->exists(), 403);
            $text = trim($input['instruction'] ?? '');
            $files = $request->file('attachments', []);
            $fingerprint = $files ? IntegrationWrites::fingerprint([$text, array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getPathname())], $files)]) : null;
            $run = AgentRuns::request($request, $row, $text, $input['submissionId'], true, $fingerprint);
            if ($files && ! DB::table('agent_messages')->where('agent_run_id', $run->id)->whereNotNull('user_id')->whereNotNull('comment_id')->exists()) {
                $comment = Comment::create(['project_id' => $model->project_id, 'task_id' => $model->id, 'author_type' => 'admin', 'author_user_id' => $request->user()->id, 'team_only' => true, 'text' => $text]);
                AttachmentStorage::storeCommentFiles($request, $comment, $model, $paths);
                $message = DB::table('agent_messages')->where('agent_run_id', $run->id)->whereNotNull('user_id')->first();
                if ($message) {
                    DB::table('agent_messages')->where('id', $message->id)->update(['comment_id' => $comment->id]);
                } else {
                    DB::table('agent_messages')->insert(['uuid' => (string) Str::uuid(), 'agent_run_id' => $run->id, 'user_id' => $request->user()->id, 'comment_id' => $comment->id, 'text' => $text, 'created_at' => now()]);
                }
            }

            return response()->json(['data' => AgentRuns::data($run, false, $row)], 201)->header('Cache-Control', 'no-store');
        });
    }

    public function cancel(Request $request, string $task, string $run): JsonResponse
    {
        AgentAccess::fields($request, []);
        $project = TaskAccess::task($request, $task)->project;

        return DB::transaction(function () use ($request, $task, $run, $project): JsonResponse {
            IntegrationWrites::lockWorkspace($project->workspace_id);
            $model = TaskAccess::task($request, $task, true);
            ProjectPermissions::authorize($request, $project, 'manage_structure', true);
            $row = DB::table('agent_runs')->where('uuid', $run)->whereIn('task_agent_assignment_id', TaskAgentAssignment::where('task_id', $model->id)->select('id'))->firstOrFail();
            if (in_array($row->state, AgentRuns::ACTIVE, true)) {
                $assignment = TaskAgentAssignment::findOrFail($row->task_agent_assignment_id);
                AgentRuns::cancel($assignment);
                $assignment->update(['state' => 'cancelled']);
                AgentRuns::event($assignment, $row, 'agent.run.cancelled');
                AgentRuns::auditHuman($request, $assignment, $row, 'agent_run_cancelled', 'cancelled');
            }

            return response()->json(['cancelled' => true]);
        }, 3);
    }
}
