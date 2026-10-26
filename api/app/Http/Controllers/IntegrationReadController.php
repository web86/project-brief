<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Project;
use App\Services\IntegrationReadAccess as Access;
use App\Services\IntegrationResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class IntegrationReadController extends Controller
{
    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['X-Content-Type-Options' => 'nosniff'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public function comments(Request $request, string $projectUuid): JsonResponse
    {
        Access::scope($request, 'comments:read');
        $project = Access::project($request, $projectUuid);
        abort_if(array_diff(array_keys($request->query()), ['kind', 'taskId', 'authorType', 'cursor', 'perPage']), 422);
        $data = $request->validate(['kind' => ['sometimes', Rule::in(['all', 'tasks', 'general'])], 'taskId' => ['sometimes', 'uuid'], 'authorType' => ['sometimes', Rule::in(['client', 'admin'])], 'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $kind = $data['kind'] ?? 'all';
        if (isset($data['taskId']) && $kind !== 'tasks') {
            throw ValidationException::withMessages(['taskId' => 'taskId requires kind=tasks.']);
        }
        $query = Access::comments($project);
        if ($kind === 'tasks') {
            $query->whereNotNull('task_id');
        } elseif ($kind === 'general') {
            $query->whereNull('task_id');
        }
        if (isset($data['taskId'])) {
            $query->where('task_id', Access::task($project, $data['taskId'])->id);
        }
        if (isset($data['authorType'])) {
            $query->where('author_type', $data['authorType']);
        }
        $perPage = $request->integer('perPage', 25);
        $context = hash('sha256', json_encode([$request->attributes->get('integration_token')->uuid, $project->uuid, $kind, $data['taskId'] ?? null, $data['authorType'] ?? null, $perPage], JSON_THROW_ON_ERROR));
        $last = 0;
        $upper = (int) (clone $query)->max('comments.id');
        if (isset($data['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
                if (($cursor['context'] ?? null) !== $context || ! is_int($cursor['last'] ?? null) || ! is_int($cursor['upper'] ?? null) || $cursor['last'] < 0 || $cursor['upper'] < $cursor['last']) {
                    throw new \UnexpectedValueException;
                }
                $last = $cursor['last'];
                $upper = $cursor['upper'];
            } catch (Throwable) {
                throw ValidationException::withMessages(['cursor' => 'Invalid cursor for this token, project or filter.']);
            }
        }
        $rows = $query->with(['task', 'projectClient', 'attachments'])->where('comments.id', '>', $last)->where('comments.id', '<=', $upper)->orderBy('comments.id')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $next = $hasMore ? Crypt::encryptString(json_encode(['context' => $context, 'last' => $rows->last()->id, 'upper' => $upper], JSON_THROW_ON_ERROR)) : null;

        return $this->json(['data' => $rows->map(fn ($comment) => IntegrationResources::comment($comment, $project)), 'meta' => ['perPage' => $perPage, 'hasMore' => $hasMore, 'nextCursor' => $next]]);
    }

    public function comment(Request $request, string $projectUuid, string $commentUuid): JsonResponse
    {
        Access::scope($request, 'comments:read');
        $project = Access::project($request, $projectUuid);

        return $this->json(['data' => IntegrationResources::comment(Access::comment($project, $commentUuid), $project)]);
    }

    public function taskAttachments(Request $request, string $projectUuid, string $taskUuid): JsonResponse
    {
        Access::scope($request, 'attachments:read');
        Access::scope($request, 'tasks:read');
        $project = Access::project($request, $projectUuid);
        $task = Access::task($project, $taskUuid);

        return $this->json(['data' => $task->attachments()->whereNull('comment_id')->get()->map(fn ($file) => IntegrationResources::attachment($file))]);
    }

    public function commentAttachments(Request $request, string $projectUuid, string $commentUuid): JsonResponse
    {
        Access::scope($request, 'attachments:read');
        Access::scope($request, 'comments:read');
        $project = Access::project($request, $projectUuid);
        $comment = Access::comment($project, $commentUuid);

        return $this->json(['data' => Access::attachments($comment)->get()->map(fn ($file) => IntegrationResources::attachment($file))]);
    }

    public function download(Request $request, string $attachmentUuid): StreamedResponse
    {
        Access::scope($request, 'attachments:read');
        $workspace = $request->attributes->get('integration_token')->workspace_id;
        $projects = Project::where('workspace_id', $workspace)->select('id');
        $file = Attachment::where('uuid', $attachmentUuid)->where(fn ($query) => $query
            ->whereHas('task', fn ($tasks) => $tasks->whereIn('project_id', $projects))
            ->orWhereHas('comment', fn ($comments) => $comments->whereIn('project_id', $projects)))->firstOrFail();
        if ($file->comment_id) {
            Access::scope($request, 'comments:read');
            $comment = $file->comment;
            $project = Project::where('workspace_id', $workspace)->findOrFail($comment->project_id ?? $comment->task?->project_id);
            Access::comment($project, $comment->uuid);
            abort_unless($file->task_id === $comment->task_id, 404);
        } else {
            Access::scope($request, 'tasks:read');
            abort_unless($file->task && $file->task->project->workspace_id === $workspace, 404);
        }
        abort_unless($file->disk === 'local' && str_starts_with($file->path, 'attachments/') && ! preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f\\\\]~', $file->path), 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'private, no-store']);
    }
}
