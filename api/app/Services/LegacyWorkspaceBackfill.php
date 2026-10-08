<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyWorkspaceBackfill
{
    public static function run(): void
    {
        DB::transaction(function (): void {
            $unassigned = DB::table('projects')->whereNull('workspace_id');
            if (! $unassigned->exists() && DB::table('workspaces')->exists()) {
                return;
            }
            // Only accounts that previously had admin access are eligible for legacy grants.
            $users = DB::table('users')->where('role', 'admin')->orderBy('created_at')->orderBy('id')->get();
            if ($users->isEmpty() && ! $unassigned->exists()) {
                return;
            }
            $workspace = DB::table('workspaces')->insertGetId([
                'uuid' => (string) Str::uuid(), 'name' => trim($users->first()?->name ?? '') ?: config('app.name'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $unassigned->update(['workspace_id' => $workspace]);
            foreach ($users as $index => $user) {
                $membership = DB::table('workspace_members')->insertGetId([
                    'workspace_id' => $workspace, 'user_id' => $user->id, 'role' => $index === 0 ? 'owner' : 'admin',
                    'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($index !== 0) {
                    DB::table('projects')->where('workspace_id', $workspace)->orderBy('id')->chunkById(100, function ($projects) use ($workspace, $membership): void {
                        DB::table('project_members')->insert($projects->map(fn ($project) => [
                            'workspace_id' => $workspace, 'project_id' => $project->id, 'workspace_member_id' => $membership,
                            'created_at' => now(), 'updated_at' => now(),
                        ])->all());
                    });
                }
            }
        });
    }
}
