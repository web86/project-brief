<?php

namespace App\Services;

use App\Services\Plans\ShadowGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationTokens
{
    public const SCOPES = ['clients:sso', 'requests:create', 'requests:read', 'requests:approve', 'projects:read', 'projects:write', 'projects:provision', 'tasks:read', 'tasks:write', 'estimates:read', 'comments:read', 'attachments:read'];

    public function create(int $workspaceId, array $data): array
    {
        return DB::transaction(function () use ($workspaceId, $data): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            app(ShadowGuard::class)->observe($workspaceId, ($data['shared'] ?? false) || ($data['agentId'] ?? null) ? 'agent.key' : (($data['scopes'] ?? []) === ['clients:sso'] ? 'sso.use' : 'api.key'));
            $secret = 'pb_'.bin2hex(random_bytes(32));
            $uuid = (string) Str::uuid();
            DB::table('integration_tokens')->insert(['agent_shared' => $data['shared'] ?? false, 'external_agent_id' => $data['agentId'] ?? null, 'integration_source_id' => $data['sourceId'] ?? null, 'uuid' => $uuid, 'workspace_id' => $workspaceId, 'name' => $data['name'], 'scopes' => json_encode($data['scopes'], JSON_THROW_ON_ERROR), 'provisioning_enabled' => in_array('projects:provision', $data['scopes'], true), 'secret_hash' => hash('sha256', $secret), 'expires_at' => $data['expiresAt'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

            return ['id' => $uuid, 'secret' => $secret];
        }, 3);
    }
}
