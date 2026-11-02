<?php

namespace App\Services\Plans;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WorkspaceUsage
{
    public function __construct(private readonly EntitlementRegistry $registry) {}

    /** Occupied capacity cannot yet be asserted without invitation/accounting/participation adapters. */
    public function measure(int $workspaceId, string $key): array
    {
        $this->registry->definition($key);
        abort_unless(DB::table('workspaces')->where('id', $workspaceId)->exists(), 404);
        $result = ['state' => 'incomplete', 'reason' => 'accounting_adapter_required', 'count' => null, 'as_of' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z')];
        if (str_starts_with($key, 'storage.') || str_ends_with($key, '.quota')) {
            return array_replace($result, ['state' => 'unavailable', 'reason' => 'capacity_or_quota_adapter_unavailable']);
        }
        if ($key === 'developers.max') {
            $members = DB::table('workspace_members')->where('workspace_id', $workspaceId)->where('status', 'active')->whereIn('role', ['owner', 'admin']);
            $owners = (clone $members)->where('role', 'owner')->distinct()->count('user_id');
            $result['observed_human_members'] = (clone $members)->distinct()->count('user_id');
            $result['reason'] = $owners === 1 ? 'invitation_and_participation_adapters_required' : 'ownership_review_required';
        }

        return $result;
    }
}
