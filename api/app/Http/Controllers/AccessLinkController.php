<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\ProjectClient;
use App\Models\Workspace;
use App\Services\AccountSecurity;
use App\Services\ClientInvitations;
use App\Services\IntegrationWrites;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\ExecutionBarrier;
use App\Services\ProjectPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccessLinkController extends Controller
{
    public function store(Request $request, Project $project, ProjectClient $client): JsonResponse
    {
        $data = $request->validate(['expiresAt' => ['nullable', 'date', 'after:now']]);
        abort_unless($client->project_id === $project->id, 404);
        abort_unless($project->status === 'active' && $client->active, 422, trans('interface.activate_access'));
        $link = DB::transaction(function () use ($request, $project, $client, $data) {
            Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $request->attributes->remove(ProjectPermissions::class);
            ProjectPermissions::authorize($request, $project, 'manage_clients', true);
            app(AccountSecurity::class)->requireFresh($request);
            abort_unless($client->refresh()->active && $project->refresh()->status === 'active', 422);

            return app(ClientInvitations::class)->issue($project, $client, $data['expiresAt'] ?? null);
        }, 3);

        return response()->json($link, 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, Project $project, ProjectClient $client, ProjectAccessToken $token): JsonResponse
    {
        abort_unless($client->project_id === $project->id && $token->project_id === $project->id && $token->project_client_id === $client->id, 404);
        DB::transaction(function () use ($request, $project, $token): void {
            Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $request->attributes->remove(ProjectPermissions::class);
            ProjectPermissions::authorize($request, $project, 'manage_clients', true);
            app(AccountSecurity::class)->requireFresh($request);
            $token->refresh()->update(['revoked_at' => $token->revoked_at ?? now()]);
        }, 3);

        return response()->json(['message' => trans('interface.access_revoked')]);
    }

    public function enter(Request $request, #[\SensitiveParameter] string $token): RedirectResponse
    {
        $access = preg_match('/^[a-f0-9]{64}$/D', $token) ? ProjectAccessToken::with('project', 'projectClient')->where('token_hash', hash('sha256', $token))->first() : null;
        $workspaceId = $access?->project?->workspace_id;
        $reason = 'invalid';
        if ($workspaceId !== null) {
            app(ExecutionBarrier::class)->catchUp($workspaceId);
            $reason = DB::transaction(function () use ($access, $workspaceId): ?string {
                IntegrationWrites::lockWorkspace($workspaceId);
                $access->refresh();
                if ($access->revoked_at) {
                    return 'invalid';
                }
                if ($access->expires_at && ! $access->expires_at->isFuture()) {
                    return 'expired';
                }
                if (! $access->isUsable() || ! app(EffectiveParticipation::class)->clientAvailable($workspaceId, $access->project_id, $access->project_client_id)) {
                    return 'unavailable';
                }
                $access->update(['last_used_at' => now()]);

                return null;
            }, 3);
        }
        $url = rtrim(config('projectbrief.frontend_url'), '/');
        if ($reason) {
            return redirect($url.'/access-error?reason='.$reason)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['client_project_id' => $access->project_id, 'project_client_id' => $access->project_client_id, 'client_access_token_id' => $access->id]);

        return redirect($url.'/project/'.$access->project->uuid)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }
}
