<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationTokens
{
    public const SCOPES = ['projects:read', 'projects:write', 'tasks:read', 'tasks:write', 'estimates:read'];

    public function create(int $workspaceId, array $data): array
    {
        $secret = 'pb_'.bin2hex(random_bytes(32));
        $uuid = (string) Str::uuid();
        DB::table('integration_tokens')->insert(['uuid' => $uuid, 'workspace_id' => $workspaceId, 'name' => $data['name'], 'scopes' => json_encode($data['scopes'], JSON_THROW_ON_ERROR), 'secret_hash' => hash('sha256', $secret), 'expires_at' => $data['expiresAt'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $uuid, 'secret' => $secret];
    }
}
