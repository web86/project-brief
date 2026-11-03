<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class OwnerProvisioning
{
    /** @param array{name: string, email: string, password: string} $data */
    public function create(array $data): User
    {
        return (new User)->getConnection()->transaction(function () use ($data): User {
            $workspace = null;
            if (! User::query()->exists() && Workspace::query()->count() === 1) {
                $candidate = Workspace::query()->lockForUpdate()->first();
                if (! $candidate->members()->exists() && $candidate->projects()->exists()) {
                    $workspace = $candidate;
                }
            }
            $workspace ??= Workspace::create(['name' => trim($data['name']) ?: config('app.name')]);
            $user = User::create($data);
            $workspace->members()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

            return $user;
        }, 3);
    }

    /** New self-service owner only: never adopts an imported or legacy workspace. */
    public function prepareVerifiedOwner(User $user): Workspace
    {
        $row = DB::table('account_onboardings')->where('user_id', $user->id)->lockForUpdate()->first();
        abort_unless($row && $user->email_verified_at, 403);
        if ($row->workspace_id) {
            return Workspace::findOrFail($row->workspace_id);
        }
        abort_if($user->platform_admin || $user->workspaceMembers()->exists(), 409, 'onboarding_membership_conflict');
        $workspace = Workspace::create(['name' => config('app.name')]);
        $workspace->members()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        DB::table('account_onboardings')->where('user_id', $user->id)->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }
}
