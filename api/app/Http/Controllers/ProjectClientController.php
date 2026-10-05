<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectClientRequest;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Services\ProjectStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProjectClientController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(['data' => $project->clients()->with('accessTokens.project', 'accessTokens.projectClient')->get()->map(function ($client) {
            $tokens = $client->accessTokens;

            return ['id' => $client->uuid, 'name' => $client->name, 'email' => $client->email, 'active' => $client->active, 'preferredLocale' => $client->preferred_locale,
                'lastUsedAt' => $tokens->max('last_used_at')?->toISOString(),
                'accessLinks' => $tokens->map(fn ($token) => ['id' => $token->id, 'expiresAt' => $token->expires_at?->toISOString(), 'revokedAt' => $token->revoked_at?->toISOString(), 'lastUsedAt' => $token->last_used_at?->toISOString(), 'active' => $token->isUsable(), 'createdAt' => $token->created_at->toISOString()])];
        })]);
    }

    public function store(ProjectClientRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($project, $data): void {
            ProjectStructure::lock($project);
            $project->clients()->create([...$data, 'name' => trim($data['name'])]);
        }, 3);

        return $this->index($project)->setStatusCode(201);
    }

    public function update(ProjectClientRequest $request, Project $project, ProjectClient $client): JsonResponse
    {
        abort_unless($client->project_id === $project->id, 404);
        $data = $request->validated();
        DB::transaction(function () use ($project, $client, $data): void {
            ProjectStructure::lock($project);
            $client->refresh()->update($data);
            if (! $client->active) {
                // Re-enabling cannot resurrect a link or an already revoked session.
                $client->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
        }, 3);

        return $this->index($project);
    }
}
