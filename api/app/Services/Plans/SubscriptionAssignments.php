<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Plans\SubscriptionPeriod;
use App\Models\Plans\WorkspaceSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SubscriptionAssignments
{
    public function __construct(private readonly PlanCommands $commands, private readonly EntitlementRegistry $registry, private readonly SubscriptionClock $clock, private readonly PlanConfiguration $validator, private readonly PlanCatalog $catalog) {}

    /** Explicit initial pin only; reassignment/expiry/rollout require the Phase 2 executor. */
    public function pin(User $actor, int $workspaceId, int $versionId, int $extraSeats, string $key, ?CarbonImmutable $startsAt = null, ?int $serviceDays = null, ?int $policyId = null): WorkspaceSubscription
    {
        $input = ['workspaceId' => $workspaceId, 'versionId' => $versionId, 'extraSeats' => $extraSeats, 'startsAt' => $startsAt?->utc()->format('Y-m-d H:i:s.u'), 'serviceDays' => $serviceDays, 'policyId' => $policyId];
        $outcome = $this->commands->run($actor, 'subscription.pin', $key, $input, function (object $authority) use ($actor, $workspaceId, $versionId, $extraSeats, $startsAt, $serviceDays, $policyId, $key, $input): array {
            abort_if(WorkspaceSubscription::where('workspace_id', $workspaceId)->exists(), 409, 'An initial subscription already exists; transitions are not active.');
            $version = PlanVersion::with('plan')->findOrFail($versionId);
            abort_unless($version->state === 'published' && $version->plan->status !== 'archived' && $version->access_class !== 'compatibility', 422, 'Published assignable version is required.');
            $this->validator->validate($this->catalog->configuration($version));
            $this->registry->seats($version->developer_seats_json, $extraSeats);
            $policy = $policyId === null ? null : PlatformPolicyVersion::findOrFail($policyId);
            if ($policy !== null && $policy->state !== 'published') {
                throw new InvalidArgumentException('Published policy is required.');
            }
            if ($version->access_class === 'paid' && $policy === null) {
                throw new InvalidArgumentException('Paid period requires a pinned policy.');
            }
            $facts = $this->clock->period($version->access_class, $startsAt ?? CarbonImmutable::now('UTC'), $serviceDays, $version->access_class === 'paid' ? $policy->grace_days : null);
            $subscription = WorkspaceSubscription::create(['workspace_id' => $workspaceId, 'approved_extra_seats' => $extraSeats, 'enforcement_mode' => 'observe', 'revision' => 1, 'materialized_phase' => $version->access_class, 'next_boundary_at' => $facts['service_ends_at']]);
            $change = DB::table('subscription_changes')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'subscription_id' => $subscription->id, 'kind' => 'assign', 'target_version_id' => $versionId, 'expected_revision' => 0, 'payload_json' => json_encode(['approved_extra_seats' => $extraSeats]), 'policy_snapshot_json' => json_encode(['id' => $policy?->id, 'hash' => $policy?->content_hash]), 'requested_by' => $actor->id, 'approved_by' => $actor->id, 'authority_revision' => $authority->revision, 'state' => 'applied', 'effective_at' => $facts['starts_at']->format('Y-m-d H:i:s.u'), 'operation_key_hash' => hash('sha256', $actor->id.'|'.$key), 'request_hash' => PlanConfiguration::hash($input), 'applied_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $period = SubscriptionPeriod::create($facts + ['workspace_id' => $workspaceId, 'subscription_id' => $subscription->id, 'entitlement_version_id' => $versionId, 'policy_version_id' => $policy?->id, 'fallback_version_id' => $policy?->fallback_version_id, 'approval_kind' => 'manual', 'approved_by' => $actor->id, 'approval_change_id' => $change]);
            $subscription->update(['current_period_id' => $period->id]);

            return ['type' => 'subscription', 'id' => $subscription->id, 'uuid' => $subscription->uuid, 'before' => 0, 'after' => 1];
        }, $workspaceId);

        return WorkspaceSubscription::findOrFail($outcome['id']);
    }

    public function override(User $actor, int $workspaceId, int $revision, string $entitlementKey, array $value, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $reason, string $key): int
    {
        $input = ['revision' => $revision, 'entitlementKey' => $entitlementKey, 'value' => $value, 'startsAt' => $startsAt->utc()->format('Y-m-d H:i:s.u'), 'endsAt' => $endsAt->utc()->format('Y-m-d H:i:s.u'), 'reason' => $reason];
        $result = $this->commands->run($actor, 'subscription.override', $key, $input, function () use ($actor, $workspaceId, $revision, $entitlementKey, $value, $startsAt, $endsAt, $reason): array {
            $subscription = WorkspaceSubscription::with('currentPeriod.version')->where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->revision === $revision, 409);
            $this->registry->validate($entitlementKey, $value);
            if ($this->registry->definition($entitlementKey)['availability'] !== 'available' || ! $this->registry->eligible($entitlementKey, $value, $subscription->currentPeriod->version->access_class) || $endsAt <= $startsAt || ! preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $reason)) {
                throw new InvalidArgumentException('Invalid or ineligible override.');
            }
            $phase = $this->clock->phase(['kind' => $subscription->currentPeriod->kind, 'starts_at' => $subscription->currentPeriod->starts_at, 'service_ends_at' => $subscription->currentPeriod->service_ends_at, 'grace_ends_at' => $subscription->currentPeriod->grace_ends_at], $startsAt);
            if (! in_array($phase['phase'], ['free', 'paid', 'trial', 'grace'], true) || ($phase['valid_until'] !== null && $endsAt > $phase['valid_until'])) {
                throw new InvalidArgumentException('Override crosses a period boundary.');
            }
            $overlap = DB::table('workspace_entitlement_overrides')->where('workspace_id', $workspaceId)->where('entitlement_key', $entitlementKey)->where('starts_at', '<', $endsAt->utc()->format('Y-m-d H:i:s.u'))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt->utc()->format('Y-m-d H:i:s.u')))->exists();
            abort_if($overlap, 409, 'Overlapping override.');
            $uuid = (string) Str::uuid();
            $id = DB::table('workspace_entitlement_overrides')->insertGetId(['uuid' => $uuid, 'workspace_id' => $workspaceId, 'subscription_id' => $subscription->id, 'entitlement_key' => $entitlementKey, 'scope_kind' => 'workspace', 'value_json' => json_encode($value, JSON_THROW_ON_ERROR), 'starts_at' => $startsAt->utc()->format('Y-m-d H:i:s.u'), 'ends_at' => $endsAt->utc()->format('Y-m-d H:i:s.u'), 'reason_code' => $reason, 'approved_by' => $actor->id, 'revision' => 1]);
            $subscription->increment('revision');

            return ['type' => 'override', 'id' => $id, 'uuid' => $uuid, 'before' => $revision, 'after' => $revision + 1];
        }, $workspaceId);

        return $result['id'];
    }
}
