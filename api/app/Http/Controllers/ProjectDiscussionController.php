<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentRequest;
use App\Models\Project;
use App\Services\CommentSubmission;
use App\Services\ProjectDiscussions;
use App\Services\TaskAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;

class ProjectDiscussionController extends Controller
{
    public function index(Request $request, Project $project, ProjectDiscussions $discussions): JsonResponse
    {
        $project = TaskAccess::project($request, $project);
        $data = $request->validate(['kind' => ['sometimes', Rule::in(['tasks', 'general'])], 'filter' => ['sometimes', Rule::in(['all', 'unread'])], 'cursor' => ['sometimes', 'string', 'max:512'], 'comment' => ['sometimes', 'uuid', 'prohibits:cursor']]);
        if (isset($data['cursor'])) {
            abort_unless(Cursor::fromEncoded($data['cursor']), 422, 'Invalid cursor.');
        }
        $query = $discussions->query($project);
        $kind = $data['kind'] ?? 'tasks';
        if (isset($data['comment'])) {
            $target = $discussions->query($project)->where('uuid', $data['comment'])->firstOrFail();
            $kind = $target->task_id === null ? 'general' : 'tasks';
            $query->where(fn ($window) => $window->where('comments.created_at', '<', $target->getRawOriginal('created_at'))
                ->orWhere(fn ($sameTime) => $sameTime->where('comments.created_at', $target->getRawOriginal('created_at'))->where('comments.id', '<=', $target->id)));
        }
        $kind === 'general' ? $query->whereNull('task_id') : $query->whereNotNull('task_id');
        if (($data['filter'] ?? 'all') === 'unread') {
            $discussions->unread($query, TaskAccess::recipient($request));
        }
        $page = $query->with(['authorUser', 'projectClient', 'task.projectSection', 'attachments.projectClient'])->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(25);
        $ids = $discussions->unread($discussions->query($project), TaskAccess::recipient($request))->whereIn('comments.id', $page->pluck('id'))->pluck('comments.id')->all();

        $counts = $discussions->counts($project, TaskAccess::recipient($request));

        return response()->json(['kind' => $kind, 'data' => $page->map(fn ($comment) => $discussions->data($comment, in_array($comment->id, $ids, true))),
            'nextCursor' => $page->nextCursor()?->encode(), 'unreadCount' => array_sum($counts), 'unreadCounts' => $counts])->header('Cache-Control', 'no-store');
    }

    public function store(CommentRequest $request, Project $project, ProjectDiscussions $discussions): JsonResponse
    {
        $project = TaskAccess::project($request, $project);
        $comment = CommentSubmission::store($request, $project);

        return response()->json(['data' => $discussions->data($comment->load(['authorUser', 'projectClient', 'attachments.projectClient']))], 201);
    }

    public function read(Request $request, Project $project, ProjectDiscussions $discussions): JsonResponse
    {
        $project = TaskAccess::project($request, $project);
        abort_if(array_diff(array_keys($request->all()), ['all', 'ids', 'kind']), 422);
        $data = $request->validate(['all' => ['sometimes', 'accepted'], 'kind' => ['sometimes', Rule::in(['general', 'tasks'])], 'ids' => ['required_without:all', 'array', 'max:100'], 'ids.*' => ['required', 'uuid']]);
        $query = $discussions->query($project);
        if (isset($data['kind'])) {
            $data['kind'] === 'general' ? $query->whereNull('task_id') : $query->whereNotNull('task_id');
        }
        if (! ($data['all'] ?? false)) {
            $query->whereIn('uuid', $data['ids']);
            abort_if((clone $query)->count() !== count(array_unique($data['ids'])), 404);
        }
        $discussions->mark($query, TaskAccess::recipient($request));

        $counts = $discussions->counts($project, TaskAccess::recipient($request));

        return response()->json(['unreadCount' => array_sum($counts), 'unreadCounts' => $counts])->header('Cache-Control', 'no-store');
    }
}
