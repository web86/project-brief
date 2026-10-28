<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\CurrentWorkspace;
use App\Services\ProjectAccess;
use App\Services\ProjectCreation;
use App\Services\ProjectUnread;
use App\Services\WebhookEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'client' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'in:active,inactive'], 'sort' => ['nullable', 'in:updated,created,name'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $member = app(CurrentWorkspace::class)->resolve($request);
        abort_if(! empty($data['client']) && $member->role !== 'owner', 403);
        $query = ProjectAccess::forMember($member);
        $search = trim($data['q'] ?? '');
        if (mb_strlen($search) >= 3) {
            $query->where(function ($match) use ($search, $member): void {
                $match->where('title', 'like', '%'.$search.'%')->orWhere(function ($clients) use ($search, $member): void {
                    if ($member->role !== 'owner') {
                        $clients->whereHas('members', fn ($assignment) => $assignment->where('workspace_member_id', $member->id)->where('permissions->manage_clients', true));
                    }
                    $clients->whereHas('clients', fn ($client) => $client->where(fn ($fields) => $fields->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')));
                });
            });
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['client'])) {
            $query->whereHas('clients', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$data['client'].'%')->orWhere('email', 'like', '%'.$data['client'].'%')));
        }
        $sort = $data['sort'] ?? 'updated';
        $query->orderBy(['updated' => 'updated_at', 'created' => 'created_at', 'name' => 'title'][$sort], $sort === 'name' ? 'asc' : 'desc')->orderByDesc('id');
        $query = app(ProjectUnread::class)->withCounts($query, $request->user())->withExists(['feedback as feedback_requested' => fn ($q) => $q->whereNotNull('requested_at'), 'feedback as feedback_submitted' => fn ($q) => $q->whereNotNull('submitted_at')])->withCount(['clients', 'members as team_count' => fn ($q) => $q->whereHas('workspaceMember', fn ($q) => $q->active()->where('role', 'admin')), 'tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')]);
        $page = $query->paginate(20)->withQueryString();
        if ($page->currentPage() > $page->lastPage()) {
            $page = $query->paginate(20, ['*'], 'page', $page->lastPage())->withQueryString();
        }

        return ProjectResource::collection($page);
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        $member = app(CurrentWorkspace::class)->resolve($request);
        $project = app(ProjectCreation::class)->create($member->workspace_id, $request->projectData(), $request->validated(), $member);

        return new ProjectResource($project->load('briefSections'));
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        $project = app(ProjectUnread::class)->withCounts(ProjectAccess::query($request)->whereKey($project->id), $request->user())->firstOrFail();
        if (! $request->boolean('summary')) {
            $project->load('briefSections');
        }

        return new ProjectResource($project->loadExists(['feedback as feedback_requested' => fn ($q) => $q->whereNotNull('requested_at'), 'feedback as feedback_submitted' => fn ($q) => $q->whereNotNull('submitted_at')])->loadCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'done')]));
    }

    public function update(ProjectRequest $request, Project $project): ProjectResource
    {
        DB::transaction(function () use ($request, $project): void {
            $project = ProjectAccess::query($request)->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->fill($request->projectData());
            $changes = $project->getDirty();
            $project->save();
            app(WebhookEvents::class)->project($project, 'project.updated', $changes);
        });

        return $this->show($request, $project);
    }
}
