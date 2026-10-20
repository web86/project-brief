<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentRequest;
use App\Models\Comment;
use App\Models\Project;
use App\Services\ProjectAccess;
use App\Services\ProjectDiscussions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;

class ProjectDiscussionController extends Controller
{
    public function index(Request $request, Project $project, ProjectDiscussions $discussions): JsonResponse
    {
        $project = ProjectAccess::project($request, $project->uuid);
        $data = $request->validate(['kind' => ['sometimes', Rule::in(['tasks', 'general'])], 'filter' => ['sometimes', Rule::in(['all', 'unread'])], 'cursor' => ['sometimes', 'string', 'max:512']]);
        if (isset($data['cursor'])) {
            abort_unless(Cursor::fromEncoded($data['cursor']), 422, 'Invalid cursor.');
        }
        $query = $discussions->query($project);
        ($data['kind'] ?? 'tasks') === 'general' ? $query->whereNull('task_id') : $query->whereNotNull('task_id');
        if (($data['filter'] ?? 'all') === 'unread') {
            $discussions->unread($query, $request->user());
        }
        $page = $query->with(['authorUser', 'projectClient', 'task.projectSection'])->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(25);
        $ids = $discussions->unread($discussions->query($project), $request->user())->whereIn('comments.id', $page->pluck('id'))->pluck('comments.id')->all();

        return response()->json(['data' => $page->map(fn ($comment) => $discussions->data($comment, in_array($comment->id, $ids, true))),
            'nextCursor' => $page->nextCursor()?->encode(), 'unreadCount' => $discussions->count($project, $request->user())])->header('Cache-Control', 'no-store');
    }

    public function store(CommentRequest $request, Project $project, ProjectDiscussions $discussions): JsonResponse
    {
        $project = ProjectAccess::project($request, $project->uuid);
        $comment = Comment::create(['project_id' => $project->id, 'text' => $request->validated('text'), 'author_type' => 'admin', 'author_user_id' => $request->user()->id]);

        return response()->json(['data' => $discussions->data($comment->load('authorUser'))], 201);
    }

    public function read(Request $request, Project $project, ProjectDiscussions $discussions): JsonResponse
    {
        $project = ProjectAccess::project($request, $project->uuid);
        abort_if(array_diff(array_keys($request->all()), ['all', 'ids']), 422);
        $data = $request->validate(['all' => ['sometimes', 'accepted'], 'ids' => ['required_without:all', 'array', 'max:100'], 'ids.*' => ['required', 'uuid']]);
        $query = $discussions->query($project);
        if (! ($data['all'] ?? false)) {
            $query->whereIn('uuid', $data['ids']);
            abort_if((clone $query)->count() !== count(array_unique($data['ids'])), 404);
        }
        $discussions->mark($query, $request->user());

        return response()->json(['unreadCount' => $discussions->count($project, $request->user())])->header('Cache-Control', 'no-store');
    }
}
