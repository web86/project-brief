<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Requests\TaskRequest;
use App\Models\Project;
use App\Services\IntegrationResources;
use App\Services\IntegrationWrites;
use App\Services\ProjectCreation;
use App\Services\TaskCreation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationApiController extends Controller
{
    private function scope(Request $request, string $scope): void
    {
        $request->attributes->set('integration_required_scope', $scope);
        abort_unless(in_array($scope, json_decode($request->attributes->get('integration_token')->scopes, true), true), 403, 'Required scope: '.$scope);
    }

    private function project(Request $request, string $uuid): Project
    {
        return Project::where('workspace_id', $request->attributes->get('integration_token')->workspace_id)->where('uuid', $uuid)->firstOrFail();
    }

    private function validateInput(Request $request, array $rules): array
    {
        abort_unless($request->isJson(), 415, 'Send a JSON object.');
        abort_if(strlen($request->getContent()) > 32768, 413, 'Maximum JSON body size is 32 KiB.');
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        abort_if(count($unknown) > 0, 422, 'Unknown fields are prohibited.');

        return $request->validate($rules);
    }

    public function projects(Request $request): JsonResponse
    {
        $this->scope($request, 'projects:read');
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = Project::where('workspace_id', $request->attributes->get('integration_token')->workspace_id)->with('briefSections')->orderBy('id')->paginate($request->integer('perPage', 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => IntegrationResources::project($p)), 'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()]]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $this->scope($request, 'projects:read');

        return response()->json(['data' => IntegrationResources::project($this->project($request, $uuid))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->scope($request, 'projects:write');
        $rules = array_intersect_key(ProjectRequest::fieldRules(true), array_flip(['title', 'website', 'currency', 'clientName', 'clientEmail']));
        $rules['title'][] = 'regex:/\S/u';
        $rules += ['sourceSystem' => ['required_with:externalId', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/D'], 'externalId' => ['required_with:sourceSystem', 'string', 'max:160', 'regex:/\S/u']];
        $data = $this->validateInput($request, $rules);

        return app(IntegrationWrites::class)->run($request, function () use ($request, $data): array {
            $workspace = $request->attributes->get('integration_token')->workspace_id;
            if (isset($data['sourceSystem'])) {
                $identity = hash('sha256', json_encode([$data['sourceSystem'], $data['externalId']], JSON_THROW_ON_ERROR));
                $link = DB::table('integration_project_links')->where('workspace_id', $workspace)->where('identity_hash', $identity)->first();
                if ($link) {
                    return [['data' => IntegrationResources::project(Project::where('workspace_id', $workspace)->findOrFail($link->project_id))], 200];
                }
            }
            $project = app(ProjectCreation::class)->create($workspace, ['title' => $data['title'], 'website_url' => $data['website'] ?? null, 'currency' => $data['currency'] ?? 'RUB'], $data)->refresh();
            if (isset($identity)) {
                DB::table('integration_project_links')->insert(['workspace_id' => $workspace, 'project_id' => $project->id, 'source_system' => $data['sourceSystem'], 'external_id' => $data['externalId'], 'identity_hash' => $identity]);
            }

            return [['data' => IntegrationResources::project($project)], 201];
        });
    }

    public function tasks(Request $request, string $uuid): JsonResponse
    {
        $this->scope($request, 'tasks:read');
        $project = $this->project($request, $uuid);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = $project->tasks()->with(['project', 'projectSection'])->orderBy('project_section_id')->orderBy('position')->orderBy('id')->paginate($request->integer('perPage', 25));
        $estimates = in_array('estimates:read', json_decode($request->attributes->get('integration_token')->scopes, true), true);

        return response()->json(['data' => $page->getCollection()->map(fn ($task) => IntegrationResources::task($task, $estimates)), 'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()]]);
    }

    public function storeTask(Request $request, string $uuid): JsonResponse
    {
        $this->scope($request, 'tasks:write');
        $project = $this->project($request, $uuid);
        $rules = TaskRequest::publicRules();
        foreach (['title', 'location', 'description'] as $field) {
            $rules[$field][] = 'regex:/\S/u';
        }
        $data = $this->validateInput($request, $rules);

        return app(IntegrationWrites::class)->run($request, function () use ($request, $project, $data): array {
            $task = app(TaskCreation::class)->create($request, $project, ['title' => $data['title'], 'location' => $data['location'], 'description' => $data['description'], 'expected_result' => $data['expectedResult'] ?? null, 'priority' => $data['priority']], $data['section'] ?? $data['location']);

            return [['data' => IntegrationResources::task($task->refresh(), false)], 201];
        });
    }
}
