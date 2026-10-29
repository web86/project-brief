<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectClient;

class ClientInvitations
{
    /** The caller holds the project lock; all client links use the existing access-token flow. */
    public function issue(Project $project, ProjectClient $client, mixed $expiresAt = null, bool $revokeExisting = true): array
    {
        abort_unless($client->active && $client->project_id === $project->id && $project->status === 'active', 422);
        $plaintext = bin2hex(random_bytes(32));
        if ($revokeExisting) {
            $client->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $plaintext), 'expires_at' => $expiresAt]);

        return ['id' => $token->id, 'url' => rtrim(config('projectbrief.frontend_url'), '/').'/access/'.$plaintext, 'expiresAt' => $token->expires_at?->toISOString()];
    }

    public function deliver(Project $project, ProjectClient $client, string $url, bool $login = false): bool
    {
        return app(AccountMail::class)->localized($client->email, $client->preferred_locale, $login ? 'client_login' : 'client_invitation', $url, ['project' => $project->title], $project->workspace()->firstOrFail(), 'client_open_project');
    }
}
