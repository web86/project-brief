<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectClientRequest;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\ClientInvitations;
use App\Services\DeveloperInvitations;
use App\Services\ProjectPermissions;
use App\Services\ProjectStructure;
use App\Services\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectClientController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(['data' => $project->clients()->with('accessTokens.project', 'accessTokens.projectClient')->get()->map(function ($client) {
            $tokens = $client->accessTokens;

            return ['id' => $client->uuid, 'name' => $client->name, 'email' => $client->email, 'active' => $client->active, 'preferredLocale' => SupportedLocales::preference($client->preferred_locale),
                'lastUsedAt' => $tokens->max('last_used_at')?->toISOString(),
                'accessLinks' => $tokens->map(fn ($token) => ['id' => $token->id, 'expiresAt' => $token->expires_at?->toISOString(), 'revokedAt' => $token->revoked_at?->toISOString(), 'lastUsedAt' => $token->last_used_at?->toISOString(), 'active' => $token->isUsable(), 'createdAt' => $token->created_at->toISOString()])];
        })]);
    }

    public function store(ProjectClientRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        $send = $request->boolean('sendInvitation');
        unset($data['sendInvitation']);
        $data['email'] = empty($data['email']) ? null : DeveloperInvitations::normalizeEmail($data['email']);
        [$client, $link, $existing] = DB::transaction(function () use ($request, $project, $data, $send): array {
            Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
            ProjectStructure::lock($project);
            $request->attributes->remove(ProjectPermissions::class);
            ProjectPermissions::authorize($request, $project, 'manage_clients', true);
            app(AccountSecurity::class)->requireFresh($request);
            $existing = $data['email'] ? $project->clients()->whereRaw('LOWER(TRIM(email)) = ?', [$data['email']])->first() : null;
            if ($existing) {
                return [$existing, null, true];
            }
            $client = $project->clients()->create([...$data, 'name' => trim($data['name']), 'preferred_locale' => SupportedLocales::storage(app()->getLocale())])->refresh();
            $link = $send ? app(ClientInvitations::class)->issue($project, $client, now()->addDays(7)) : null;

            return [$client, $link, false];
        }, 3);

        $delivery = $existing ? 'existing' : ($link ? (app(ClientInvitations::class)->deliver($project, $client, $link['url']) ? 'sent' : 'failed') : 'not_requested');

        $response = $this->index($project);

        return $response->setData([...$response->getData(true), 'invitation' => $delivery])->setStatusCode($existing ? 200 : 201);
    }

    public function invite(Request $request, Project $project, ProjectClient $client): JsonResponse
    {
        abort_unless($client->project_id === $project->id, 404);
        abort_unless($client->email, 422);
        $link = DB::transaction(function () use ($request, $project, $client): array {
            Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
            ProjectStructure::lock($project);
            $request->attributes->remove(ProjectPermissions::class);
            ProjectPermissions::authorize($request, $project, 'manage_clients', true);
            app(AccountSecurity::class)->requireFresh($request);

            return app(ClientInvitations::class)->issue($project->refresh(), $client->refresh(), now()->addDays(7));
        }, 3);
        $delivery = app(ClientInvitations::class)->deliver($project, $client, $link['url']) ? 'sent' : 'failed';
        $response = $this->index($project);

        return $response->setData([...$response->getData(true), 'invitation' => $delivery]);
    }

    public function update(ProjectClientRequest $request, Project $project, ProjectClient $client): JsonResponse
    {
        abort_unless($client->project_id === $project->id, 404);
        $data = $request->validated();
        DB::transaction(function () use ($request, $project, $client, $data): void {
            Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
            ProjectStructure::lock($project);
            $request->attributes->remove(ProjectPermissions::class);
            ProjectPermissions::authorize($request, $project, 'manage_clients', true);
            app(AccountSecurity::class)->requireFresh($request);
            $client->refresh()->update($data);
            if (! $client->active) {
                // Re-enabling cannot resurrect a link or an already revoked session.
                $client->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
        }, 3);

        return $this->index($project);
    }
}
