<?php

namespace App\Services\Plans;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExecutionBarrier
{
    public const FEATURES = [
        'agent' => ['agents.enabled', 'api.agents.enabled'],
        'webhook' => ['webhooks.enabled'],
        'api' => ['api.external.enabled'],
        'sso' => ['sso.enabled'],
    ];

    public function __construct(private readonly SubscriptionLifecycle $lifecycle, private readonly EntitlementResolver $resolver) {}

    /** Entry point before acquiring business locks. Recheck with require() at the side effect. */
    public function catchUp(int $workspaceId): void
    {
        if (app(EffectiveParticipation::class)->boundaryDue($workspaceId)) {
            $this->lifecycle->catchUp($workspaceId);
        }
    }

    /** Return the epoch for new work, or compare the immutable receipt before replaying work. */
    public function require(int $workspaceId, string $group, ?object $receipt = null, bool $lockReads = true): ?int
    {
        $keys = self::FEATURES[$group] ?? throw new \InvalidArgumentException('Unknown execution group.');
        $current = $lockReads && DB::transactionLevel() > 0;
        $subscription = DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)
            ->when($current, fn ($q) => $q->sharedLock())->first(['enforcement_mode']);
        $epoch = DB::table('workspace_execution_epochs')->where('workspace_id', $workspaceId)->where('feature_group', $group)
            ->when($current, fn ($q) => $q->sharedLock())->first();
        abort_if($epoch && $epoch->state !== 'ready', 403, 'subscription_execution_blocked');
        if ($receipt !== null) {
            abort_if($epoch
                ? ($receipt->execution_feature_group ?? null) !== $group || (string) ($receipt->execution_epoch ?? '') !== (string) $epoch->epoch
                : ($receipt->execution_epoch ?? null) !== null, 409, 'subscription_execution_stale');
        }

        if ($epoch || $subscription?->enforcement_mode === 'enforce') {
            $resolved = $this->resolver->resolve($workspaceId, current: $current, inspectSuspended: $epoch?->resume_at !== null);
            abort_unless($resolved['state'] === 'available', 403, 'subscription_execution_blocked');
            foreach ($keys as $key) {
                abort_unless(($resolved['values'][$key]['value'] ?? null) === true, 403, 'subscription_feature_unavailable');
            }
        }

        return $epoch ? (int) $epoch->epoch : null;
    }

    public function allows(int $workspaceId, string $group, ?object $receipt = null, bool $lockReads = true): bool
    {
        try {
            $this->require($workspaceId, $group, $receipt, $lockReads);

            return true;
        } catch (HttpException $e) {
            if (! in_array($e->getStatusCode(), [403, 409], true)) {
                throw $e;
            }

            return false;
        }
    }

    public function receipt(string $group, ?int $epoch): array
    {
        return ['execution_epoch' => $epoch, 'execution_feature_group' => $epoch === null ? null : $group];
    }
}
