<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Services\AgentAccess;
use App\Services\AttachmentStorage;
use App\Services\IntegrationWrites;
use App\Services\ProjectPermissions;
use App\Services\TaskAccess;
use App\Services\TaskAudit;
use App\Services\TeamChat;
use App\Services\WebhookEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgentResultController extends Controller
{
    public function store(Request $request, string $task, string $comment): JsonResponse
    {
        AgentAccess::fields($request, ['text', 'submissionId', 'links', 'attachmentIds']);
        $data = $request->validate(['text' => ['required', 'string', 'max:5000', 'regex:/\S/u'], 'submissionId' => ['required', 'uuid'], 'links' => ['present', 'array', 'max:2'], 'links.*' => ['required', 'distinct', Rule::in(['pullRequestUrl', 'resultUrl'])], 'attachmentIds' => ['present', 'array', 'max:5'], 'attachmentIds.*' => ['required', 'uuid', 'distinct']]);
        $project = TaskAccess::task($request, $task)->project;

        return AttachmentStorage::atomic(function (array &$paths) use ($request, $task, $comment, $project, $data): JsonResponse {
            IntegrationWrites::lockWorkspace($project->workspace_id);
            $model = TaskAccess::task($request, $task, true);
            app(TeamChat::class)->authorize($request, $model->project, true);
            ProjectPermissions::authorize($request, $model->project, 'manage_structure', true);
            $source = Comment::withoutGlobalScope('public_channel')->where('uuid', $comment)->where('task_id', $model->id)->where('project_id', $model->project_id)->where('author_type', 'agent')->where('team_only', true)->firstOrFail();
            $files = $source->attachments()->withoutGlobalScope('public_channel')->where('task_id', $model->id)->whereIn('uuid', $data['attachmentIds'])->get();
            abort_unless($files->count() === count($data['attachmentIds']), 404);
            abort_if($files->sum('size') > 40 * 1024 * 1024, 422);
            $text = trim($data['text']);
            foreach ($data['links'] as $field) {
                $url = $source->agentReport?->data()[$field] ?? null;
                abort_unless($url, 422);
                $text .= "\n".$url;
            }
            $key = hash('sha256', 'agent-share:'.$source->id.':'.$request->user()->id.':'.$data['submissionId']);
            $fingerprint = IntegrationWrites::fingerprint(['text' => $text, 'files' => $files->pluck('uuid')->sort()->values()->all()]);
            if ($existing = Comment::where('submission_key', $key)->first()) {
                abort_unless(hash_equals($existing->submission_fingerprint, $fingerprint), 409);

                return response()->json(['data' => ['id' => $existing->uuid]], 200);
            }
            $copy = Comment::create(['project_id' => $model->project_id, 'task_id' => $model->id, 'author_type' => 'admin', 'author_user_id' => $request->user()->id, 'text' => $text, 'team_only' => false, 'submission_key' => $key, 'submission_fingerprint' => $fingerprint]);
            foreach ($files as $file) {
                abort_unless($file->disk === 'local' && Storage::disk('local')->exists($file->path), 404);
                $name = Str::uuid().'.'.pathinfo($file->stored_name, PATHINFO_EXTENSION);
                $destination = 'attachments/comments/'.$copy->uuid.'/'.$name;
                $paths[] = $destination;
                if (! Storage::disk('local')->copy($file->path, $destination)) {
                    throw new \RuntimeException('Unable to copy selected attachment');
                }
                $thumbnail = null;
                if ($file->thumbnail_path) {
                    abort_unless(Storage::disk('local')->exists($file->thumbnail_path), 404);
                    $thumbnail = $destination.'.thumbnail.jpg';
                    $paths[] = $thumbnail;
                    if (! Storage::disk('local')->copy($file->thumbnail_path, $thumbnail)) {
                        throw new \RuntimeException('Unable to copy selected thumbnail');
                    }
                }
                $copy->attachments()->create(['task_id' => $model->id, 'original_name' => $file->original_name, 'stored_name' => $name, 'mime_type' => $file->mime_type, 'size' => $file->size, 'disk' => 'local', 'path' => $destination, 'thumbnail_path' => $thumbnail, 'uploaded_by_type' => 'admin']);
            }
            TaskAudit::record($request, $model, 'comment', 'Developer shared an agent result', metadata: ['commentId' => $copy->id, 'agentSourceCommentId' => $source->id]);
            app(WebhookEvents::class)->clientComment($copy, $model->project, $model);

            return response()->json(['data' => ['id' => $copy->uuid]], 201);
        });
    }
}
