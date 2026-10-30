<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentRequest;
use App\Models\Attachment;
use App\Models\Project;
use App\Services\CommentSubmission;
use App\Services\LiveSync;
use App\Services\ProjectDiscussions;
use App\Services\TeamChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamChatController extends Controller
{
    public function index(Request $request, Project $project, TeamChat $chat): JsonResponse
    {
        $chat->authorize($request, $project);
        $data = $request->validate(['before' => ['sometimes', 'integer', 'min:1']]);
        $rows = $chat->query($project)->with(['authorUser', 'attachments' => fn ($query) => $query->withoutGlobalScope('public_channel')])->when(isset($data['before']), fn ($query) => $query->where('id', '<', $data['before']))->orderByDesc('id')->limit(51)->get();
        $more = $rows->count() > 50;
        $rows = $rows->take(50);

        return response()->json(['data' => $rows->reverse()->values()->map(fn ($row) => $chat->data($row, $request, $project)), 'before' => $more ? $rows->last()->id : null, 'unread' => $chat->count($project, $request->user())])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Project $project, string $message, TeamChat $chat): JsonResponse
    {
        $chat->authorize($request, $project);

        return response()->json(['data' => $chat->data($chat->query($project)->where('uuid', $message)->firstOrFail(), $request)])->header('Cache-Control', 'no-store');
    }

    public function store(CommentRequest $request, Project $project, TeamChat $chat): JsonResponse
    {
        $chat->authorize($request, $project);
        $request->validate(['submissionId' => ['required', 'uuid']]);
        $message = CommentSubmission::store($request, $project, null, true);

        return response()->json(['data' => $chat->data($message, $request, $project)], 201)->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, Project $project, TeamChat $chat): JsonResponse
    {
        $chat->authorize($request, $project);
        abort_if(array_diff(array_keys($request->all()), ['through']), 422);
        $data = $request->validate(['through' => ['required', 'integer', 'min:1']]);
        if (app(ProjectDiscussions::class)->mark($chat->query($project)->where('id', '<=', $data['through']), $request->user())) {
            app(LiveSync::class)->record($project->workspace_id, $project->id, 'team');
        }

        return response()->json(['unread' => $chat->count($project, $request->user())]);
    }

    public function file(Request $request, Project $project, string $attachment, string $mode, TeamChat $chat): StreamedResponse
    {
        $chat->authorize($request, $project);
        $file = Attachment::withoutGlobalScope('public_channel')->where('uuid', $attachment)->whereIn('comment_id', $chat->query($project)->select('id'))->firstOrFail();
        abort_unless(in_array($mode, ['download', 'preview', 'original'], true), 404);
        abort_if($mode !== 'download' && ! in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        $thumbnail = $mode === 'preview' && $file->thumbnail_path;
        $path = $thumbnail ? $file->thumbnail_path : $file->path;
        abort_unless(Storage::disk('local')->exists($path), 404);
        $headers = ['Content-Type' => $thumbnail ? 'image/jpeg' : $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer'];

        return $mode === 'download' ? Storage::disk('local')->download($path, $file->original_name, $headers) : Storage::disk('local')->response($path, $file->original_name, $headers, 'inline');
    }
}
