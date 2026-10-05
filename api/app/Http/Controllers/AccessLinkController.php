<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\ProjectClient;
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
        abort_unless($project->status === 'active' && $client->active, 422, 'Сначала активируйте проект и клиента.');
        $plaintext = bin2hex(random_bytes(32));
        $token = DB::transaction(function () use ($project, $client, $plaintext, $data) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($client->refresh()->active && $project->refresh()->status === 'active', 422);
            $client->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

            return $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $plaintext), 'expires_at' => $data['expiresAt'] ?? null]);
        }, 3);

        return response()->json(['id' => $token->id, 'url' => rtrim(config('projectbrief.frontend_url'), '/').'/access/'.$plaintext, 'expiresAt' => $token->expires_at?->toISOString()], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Project $project, ProjectClient $client, ProjectAccessToken $token): JsonResponse
    {
        abort_unless($client->project_id === $project->id && $token->project_id === $project->id && $token->project_client_id === $client->id, 404);
        DB::transaction(function () use ($project, $token): void {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $token->refresh()->update(['revoked_at' => $token->revoked_at ?? now()]);
        }, 3);

        return response()->json(['message' => 'Доступ отозван.']);
    }

    public function enter(Request $request, #[\SensitiveParameter] string $token): RedirectResponse
    {
        $access = preg_match('/^[a-f0-9]{64}$/D', $token) ? ProjectAccessToken::with('project', 'projectClient')->where('token_hash', hash('sha256', $token))->first() : null;
        $reason = ! $access || $access->revoked_at ? 'invalid' : (($access->expires_at && ! $access->expires_at->isFuture()) ? 'expired' : ($access->project?->status !== 'active' ? 'unavailable' : null));
        if (! $reason && ! $access->isUsable()) {
            $reason = 'unavailable';
        }
        $url = rtrim(config('projectbrief.frontend_url'), '/');
        if ($reason) {
            return redirect($url.'/access-error?reason='.$reason)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['client_project_id' => $access->project_id, 'project_client_id' => $access->project_client_id, 'client_access_token_id' => $access->id]);
        $access->update(['last_used_at' => now()]);

        return redirect($url.'/project/'.$access->project->uuid)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }
}
