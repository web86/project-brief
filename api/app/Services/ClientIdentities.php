<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientIdentities
{
    /** Workspace lock is held by the caller. Contacts never authenticate or grant access. */
    public function resolve(int $workspaceId, array $client): object
    {
        $hash = hash('sha256', $client['email']);
        $existing = DB::table('workspace_clients')->where('workspace_id', $workspaceId)->where('email_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }
        $legacy = DB::table('project_clients')->join('projects', 'projects.id', '=', 'project_clients.project_id')
            ->where('projects.workspace_id', $workspaceId)->whereNull('projects.deleted_at')
            ->whereRaw('LOWER(TRIM(project_clients.email)) = ?', [$client['email']])->orderBy('project_clients.id')->value('project_clients.name');
        $id = DB::table('workspace_clients')->insertGetId([
            'uuid' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'name' => $legacy ?? $client['name'],
            'email' => $client['email'], 'email_hash' => $hash, 'company' => $client['company'], 'website' => $client['website'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('workspace_clients')->find($id);
    }
}
