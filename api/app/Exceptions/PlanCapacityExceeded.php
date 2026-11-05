<?php

namespace App\Exceptions;

use App\Services\Plans\WorkspaceUsage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PlanCapacityExceeded extends HttpException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $entitlement,
        public readonly int $maximum,
        public readonly int $attemptedUsage,
        public readonly int $versionId,
    ) {
        parent::__construct(403, 'subscription_capacity_exceeded');
    }

    public function details(): array
    {
        // Exception rendering runs after the failed allocation has rolled back.
        $usage = $plan = null;
        try {
            $usage = app(WorkspaceUsage::class)->snapshot($this->workspaceId, false, [$this->entitlement], effective: true)[$this->entitlement];
            $plan = DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
                ->where('plan_versions.id', $this->versionId)->value('plans.code');
        } catch (\Throwable $exception) {
            report($exception);
        }

        return [
            'entitlement' => $this->entitlement,
            'usage' => ($usage['state'] ?? null) === 'ready' ? $usage['count'] : null,
            'maximum' => $this->maximum,
            'attemptedUsage' => $this->attemptedUsage,
            'plan' => $plan,
        ];
    }
}
