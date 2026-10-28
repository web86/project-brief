<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFeedback;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use App\Services\ProjectFeedbacks;
use App\Services\ProjectStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectFeedbackController extends Controller
{
    public function request(Request $request, Project $project, ProjectFeedbacks $feedbacks): JsonResponse
    {
        DB::transaction(function () use ($request, $project, $feedbacks): void {
            Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
            $member = app(CurrentWorkspace::class)->resolve($request, true);
            $project = ProjectAccess::forMember($member)->whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($feedbacks->eligible($project), 409, trans('feedback.incomplete'));
            if ($project->feedback()->whereNotNull('submitted_at')->exists()) {
                return;
            }
            foreach ($project->clients()->where('active', true)->get() as $client) {
                $entry = $feedbacks->entry($project, $client);
                if (! $entry->requested_at && ! $entry->submitted_at) {
                    $entry->update(['requested_at' => now()]);
                }
            }
        }, 3);

        return response()->json(['requested' => true]);
    }

    public function review(Request $request, Project $project): JsonResponse
    {
        $member = app(CurrentWorkspace::class)->resolve($request);
        abort_unless($member->role === 'owner', 403);
        ProjectAccess::forMember($member)->whereKey($project->id)->firstOrFail();

        return response()->json(['data' => $project->feedback()->whereNotNull('submitted_at')->with('projectClient')->orderByDesc('submitted_at')->get()->map(fn ($row) => [
            'rating' => $row->rating, 'text' => $row->text, 'author' => $row->projectClient?->name, 'submittedAt' => $row->submitted_at->toISOString(),
        ])])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Project $project, ProjectFeedbacks $feedbacks): JsonResponse
    {
        return response()->json($feedbacks->clientState($project, $request->attributes->get('project_client')));
    }

    public function invitation(Request $request, Project $project, ProjectFeedbacks $feedbacks): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:claim,dismiss']]);
        $client = $request->attributes->get('project_client');
        $autoOpen = DB::transaction(function () use ($project, $client, $data, $feedbacks): bool {
            ProjectStructure::lock($project);
            abort_unless($feedbacks->eligible($project), 409, trans('feedback.incomplete'));
            if (ProjectFeedback::where('project_id', $project->id)->whereNotNull('submitted_at')->exists()) {
                return false;
            }
            $entry = $feedbacks->entry($project, $client);
            if ($data['action'] === 'dismiss') {
                $entry->update(['shown_at' => $entry->shown_at ?? now(), 'dismissed_at' => $entry->dismissed_at ?? now()]);

                return false;
            }
            if (! $entry->requested_at || $entry->shown_at || $entry->dismissed_at) {
                return false;
            }
            $entry->update(['shown_at' => now()]);

            return true;
        }, 3);

        return response()->json([...$feedbacks->clientState($project, $client), 'autoOpen' => $autoOpen]);
    }

    public function store(Request $request, Project $project, ProjectFeedbacks $feedbacks): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'text' => ['nullable', 'string', 'max:5000']]);
        abort_if(array_diff(array_keys($request->all()), ['rating', 'text']), 422);
        $client = $request->attributes->get('project_client');
        $feedback = DB::transaction(function () use ($project, $client, $data, $feedbacks): ProjectFeedback {
            ProjectStructure::lock($project);
            $entry = ProjectFeedback::where('project_id', $project->id)->where('project_client_id', $client->id)->first();
            if ($entry?->submitted_at) {
                return $entry;
            }
            abort_if(ProjectFeedback::where('project_id', $project->id)->whereNotNull('submitted_at')->exists(), 409);
            abort_unless($feedbacks->eligible($project), 409, trans('feedback.incomplete'));
            $entry ??= $feedbacks->entry($project, $client);
            $entry->update(['rating' => $data['rating'], 'text' => trim($data['text'] ?? '') ?: null, 'submitted_at' => now(), 'shown_at' => $entry->shown_at ?? now()]);
            $feedbacks->recordMail($entry);
            app()->terminating(fn () => $feedbacks->deliver($entry->id));

            return $entry;
        }, 3);

        return response()->json(['submitted' => true, 'submittedAt' => $feedback->submitted_at->toISOString()]);
    }
}
