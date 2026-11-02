<?php

namespace App\Services\Plans;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PlanAuthority
{
    /** Internal capability check. Future HTTP adapters must additionally require existing MFA/strong/fresh session proofs. */
    public function requirePrimary(User $actor): object
    {
        $authority = DB::table('platform_plan_authority')->where('id', 1)->lockForUpdate()->first();
        abort_unless($authority && (int) $authority->primary_user_id === (int) $actor->id && User::whereKey($actor->id)->where('platform_admin', true)->lockForUpdate()->first() !== null, 403, 'Primary plan authority is required.');

        return $authority;
    }
}
