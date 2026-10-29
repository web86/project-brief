<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureClientProjectAccess;
use App\Models\Project;
use App\Services\ClientInvitations;
use App\Services\DeveloperInvitations;
use App\Services\IntegrationWrites;
use App\Services\SafeExceptionReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientProjectLoginController extends Controller
{
    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $access = app(EnsureClientProjectAccess::class)->resolve($request);
        abort_if($request->user() || ($access && $access->project->uuid !== $uuid), 403);
        $email = DeveloperInvitations::normalizeEmail($data['email']);
        $project = Project::where('uuid', $uuid)->where('status', 'active')->first();
        $delivery = $project ? DB::transaction(function () use ($project, $email): ?array {
            IntegrationWrites::lockWorkspace($project->workspace_id);
            $project = Project::whereKey($project->id)->where('status', 'active')->lockForUpdate()->first();
            $client = $project?->clients()->where('active', true)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if (! $client) {
                return null;
            }
            // A public login request must never revoke a previously verified session.
            $link = app(ClientInvitations::class)->issue($project, $client, now()->addMinutes(30), revokeExisting: false);

            return [$project, $client, $link];
        }, 3) : null;
        if ($delivery) {
            [$project, $client, $link] = $delivery;
            try {
                $sent = app(ClientInvitations::class)->deliver($project, $client, $link['url']);
            } catch (Throwable $exception) {
                app(SafeExceptionReporter::class)->report($exception);
                $sent = false;
            }
            if (! $sent) {
                $client->accessTokens()->whereKey($link['id'])->update(['revoked_at' => now()]);
            }
        }

        return response()->json(['accepted' => true], 202)->header('Cache-Control', 'no-store');
    }
}
