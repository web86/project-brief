<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccessLinkController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['expiresAt' => ['nullable', 'date', 'after:now']]);
        abort_unless($project->status === 'active', 422, 'Сначала активируйте проект.');
        $plaintext = bin2hex(random_bytes(32));
        $token = DB::transaction(function () use ($project, $plaintext, $data) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

            return $project->accessTokens()->create(['token_hash' => hash('sha256', $plaintext), 'expires_at' => $data['expiresAt'] ?? null]);
        });

        return response()->json(['id' => $token->id, 'url' => rtrim(config('projectbrief.frontend_url'), '/').'/access/'.$plaintext, 'expiresAt' => $token->expires_at?->toISOString()], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Project $project, ProjectAccessToken $token): JsonResponse
    {
        abort_unless($token->project_id === $project->id, 404);
        $token->update(['revoked_at' => $token->revoked_at ?? now()]);

        return response()->json(['message' => 'Доступ отозван.']);
    }

    public function enter(Request $request, string $token): RedirectResponse
    {
        $access = preg_match('/^[a-f0-9]{64}$/D', $token) ? ProjectAccessToken::with('project')->where('token_hash', hash('sha256', $token))->first() : null;
        $reason = ! $access || $access->revoked_at ? 'invalid' : ($access->expires_at?->isPast() ? 'expired' : ($access->project?->status !== 'active' ? 'unavailable' : null));
        $url = rtrim(config('projectbrief.frontend_url'), '/');
        if ($reason) {
            return redirect($url.'/access-error?reason='.$reason)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['client_project_id' => $access->project_id, 'client_access_token_id' => $access->id]);
        $access->update(['last_used_at' => now()]);

        return redirect($url.'/project/'.$access->project->uuid)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control','no-store');
    }
}
