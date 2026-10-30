<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PlatformAudit
{
    public function record(?int $administrator, string $action, string $objectType = 'platform', ?string $objectId = null, string $result = 'success'): void
    {
        DB::table('platform_audit')->insert(['created_at' => now(), 'administrator_id' => $administrator, 'action' => $action, 'object_type' => $objectType, 'object_id' => $objectId, 'result' => $result]);
    }
}
