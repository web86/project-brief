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
        return DB::transaction(function () use ($data): User {
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
}
