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

    /** Approve a paid period after a frozen platform review. Existing suspension remains in force. */
    public function approvePaid(User $actor, int $workspaceId, string $previewId, int $revision, int $days, string $reason, string $key): array
    {
        app(ExecutionBarrier::class)->catchUp($workspaceId);
        $input = compact('previewId', 'revision', 'days', 'reason');
        $outcome = $this->commands->run($actor, 'subscription.approve_paid', $key, $input, function (object $authority) use ($actor, $workspaceId, $previewId, $revision, $days, $reason, $key, $input): array {
            abort_unless(preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $reason), 422);
            $subscription = WorkspaceSubscription::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->revision === $revision && $subscription->enforcement_mode === 'enforce', 409, 'subscription_revision_conflict');
            $current = $subscription->currentPeriod()->firstOrFail();
            $now = CarbonImmutable::now('UTC');
            $phase = $this->clock->phase($current->only(['kind', 'starts_at', 'service_ends_at', 'grace_ends_at']), $now)['phase'];
            abort_unless(in_array($phase, ['free', 'paid', 'grace'], true) && ($phase !== 'free' || $current->approval_kind === 'system'), 409, 'paid_approval_unavailable');

            abort_if(DB::table('subscription_changes')->where('workspace_id', $workspaceId)->where('state', 'scheduled')->exists(), 409, 'subscription_renewal_already_scheduled');
            $preview = app(FrozenTransitions::class)->read($actor, $workspaceId, true, $previewId);
            abort_unless((int) $preview['subscriptionRevision'] === $revision
                && ! array_diff($preview['reasonCodes'], ['subscription_missing_or_incomplete', 'participation_cycle_adapter_required']), 409, 'transition_preview_stale');
            $target = PlanVersion::where('uuid', $preview['target']['id'])->where('state', 'published')->where('access_class', 'paid')->firstOrFail();
            abort_if($phase !== 'free' && ((int) $target->id !== (int) $current->entitlement_version_id
                || array_filter($preview['wouldHold'])), 409, 'renewal_version_conflict');
            $policyId = DB::table('plan_settings')->where('id', 1)->sharedLock()->value('current_policy_version_id');
            $policy = PlatformPolicyVersion::where('state', 'published')->findOrFail($policyId);
            $fallback = PlanVersion::where('state', 'published')->where('access_class', 'free')->findOrFail($policy->fallback_version_id);
            abort_unless($policy->grace_days === 7 && $policy->trial_days === 7
                && hash_equals($policy->content_hash, PlanConfiguration::hash(app(PlatformPolicyCatalog::class)->configuration($policy))), 409, 'commercial_policy_required');

            $previewRow = DB::table('plan_previews')->where('uuid', $previewId)->firstOrFail();
            $now = CarbonImmutable::now('UTC');
            abort_unless($phase === $this->clock->phase($current->only(['kind', 'starts_at', 'service_ends_at', 'grace_ends_at']), $now)['phase'], 409, 'subscription_revision_conflict');
            $scheduled = $phase === 'paid';
            $startsAt = $scheduled ? $current->service_ends_at : $now;
            $facts = $this->clock->period('paid', $startsAt, $days, $policy->grace_days);
            $change = DB::table('subscription_changes')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'subscription_id' => $subscription->id,
                'kind' => $phase === 'free' ? 'assign' : 'renew',
                'target_version_id' => $target->id,
                'expected_revision' => $revision,
                'payload_json' => json_encode(['reason' => $reason, 'selectionHash' => $preview['selectionHash']], JSON_THROW_ON_ERROR),
                'policy_snapshot_json' => json_encode(['id' => $policy->id, 'hash' => $policy->content_hash], JSON_THROW_ON_ERROR),
                'preview_id' => $previewRow->id,
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'authority_revision' => $authority->revision,
                'state' => $scheduled ? 'scheduled' : 'applied',
                'effective_at' => $startsAt->format('Y-m-d H:i:s.u'),
                'operation_key_hash' => hash('sha256', $actor->id.'|'.$key),
                'request_hash' => PlanConfiguration::hash($input),
                'applied_at' => $scheduled ? null : $now->format('Y-m-d H:i:s.u'),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
            $period = SubscriptionPeriod::create($facts + [
                'workspace_id' => $workspaceId,
                'subscription_id' => $subscription->id,
                'entitlement_version_id' => $target->id,
                'policy_version_id' => $policy->id,
                'fallback_version_id' => $fallback->id,
                'approval_kind' => 'manual',
                'approved_by' => $actor->id,
                'approval_change_id' => $change,
            ]);
            if ($scheduled) {
                $subscription->increment('revision');
            } else {
                $subscription->update(['current_period_id' => $period->id, 'materialized_phase' => 'paid', 'revision' => $revision + 1, 'next_boundary_at' => $period->service_ends_at]);
            }

            return ['type' => 'subscription', 'id' => $subscription->id, 'uuid' => $subscription->uuid, 'before' => $revision, 'after' => $revision + 1,
                'state' => $scheduled ? 'scheduled' : 'applied', 'effectiveAt' => $startsAt->toISOString()];
        }, $workspaceId);

        return ['id' => $outcome['uuid'], 'revision' => (string) $outcome['after'], 'state' => $outcome['state'] ?? 'applied', 'effectiveAt' => $outcome['effectiveAt'] ?? null];
    }

    public function manualVersions(): array
    {
        return PlanVersion::where('state', 'published')->with('plan')
            ->whereHas('plan', fn ($query) => $query->whereIn('code', ['basic', 'freelancer', 'studio'])->where('status', 'published')->whereColumn('plans.published_version_id', 'plan_versions.id'))
            ->where(fn ($query) => $query->where(fn ($free) => $free->where('access_class', 'free')->whereHas('plan', fn ($plan) => $plan->where('code', 'basic')))
                ->orWhere(fn ($paid) => $paid->where('access_class', 'paid')->whereHas('plan', fn ($plan) => $plan->whereIn('code', ['freelancer', 'studio']))))
            ->sharedLock()->get()->map(fn (PlanVersion $version): array => [
                'id' => $version->uuid,
                'code' => $version->plan->code,
                'translations' => $version->translations_json,
                'accessClass' => $version->access_class,
            ])->sortBy('code')->values()->all();
    }

    public function assignManual(User $actor, int $workspaceId, string $previewId, int $revision, ?string $endDate, bool $replace, string $key): array
    {
        $input = compact('previewId', 'revision', 'endDate', 'replace');

        return $this->commands->run($actor, 'subscription.assign_manual', $key, $input, function (object $authority) use ($actor, $workspaceId, $previewId, $revision, $endDate, $replace, $key, $input): array {
            $subscription = WorkspaceSubscription::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->revision === $revision && $subscription->enforcement_mode === 'enforce', 409, 'subscription_revision_conflict');
            $source = $subscription->currentPeriod()->firstOrFail();
            abort_if($source->kind === 'compatibility', 409, 'manual_assignment_unavailable');
            abort_if(in_array($source->kind, ['trial', 'paid'], true) && ! $replace, 422, 'manual_assignment_replacement_required');
            abort_if(DB::table('workspace_entitlement_overrides')->where('subscription_id', $subscription->id)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now('UTC')))->exists(), 409, 'manual_assignment_overrides_require_review');
            $transitions = app(FrozenTransitions::class);
            $preview = $transitions->read($actor, $workspaceId, true, $previewId);
            abort_unless(($preview['manualAssignment'] ?? false) && $preview['availableAction'] === 'assign-manual' &&
                (int) $preview['subscriptionRevision'] === $revision && in_array($preview['target']['id'], array_column($this->manualVersions(), 'id'), true), 409, 'transition_preview_stale');
            $target = PlanVersion::where('uuid', $preview['target']['id'])->firstOrFail();
            $policy = PlatformPolicyVersion::where('state', 'published')->findOrFail(
                DB::table('plan_settings')->where('id', 1)->sharedLock()->value('current_policy_version_id'),
            );
            $fallback = PlanVersion::where('state', 'published')->where('access_class', 'free')->findOrFail($policy->fallback_version_id);
            abort_unless(hash_equals($policy->content_hash, PlanConfiguration::hash(app(PlatformPolicyCatalog::class)->configuration($policy))), 409, 'commercial_policy_required');
            $previewRow = DB::table('plan_previews')->where('uuid', $previewId)->firstOrFail();
            $now = CarbonImmutable::now('UTC');
            abort_if($subscription->next_boundary_at && $subscription->next_boundary_at <= $now, 409, 'transition_preview_stale');
            $kind = $target->access_class === 'free' ? 'free' : 'paid';
            $facts = $this->clock->manualPeriod($kind, $now, $endDate, $policy->grace_days);
            $timestamp = $now->format('Y-m-d H:i:s.u');

            $scheduled = DB::table('subscription_changes')->where('subscription_id', $subscription->id)->where('state', 'scheduled');
            if ($scheduled->exists()) {
                abort_unless($replace, 422, 'manual_assignment_replacement_required');
                $scheduled->update(['state' => 'cancelled', 'updated_at' => $timestamp]);
            }
            $change = DB::table('subscription_changes')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'subscription_id' => $subscription->id,
                'kind' => 'transition',
                'target_version_id' => $target->id,
                'expected_revision' => $revision,
                'payload_json' => json_encode(['reason' => 'operator_manual_assignment', 'selectionHash' => $preview['selectionHash'], 'sourcePeriodId' => $source->id, 'endDate' => $endDate, 'replacementConfirmed' => $replace], JSON_THROW_ON_ERROR),
                'policy_snapshot_json' => json_encode(['id' => $policy->id, 'hash' => $policy->content_hash], JSON_THROW_ON_ERROR),
                'preview_id' => $previewRow->id,
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'authority_revision' => $authority->revision,
                'state' => 'applied',
                'effective_at' => $timestamp,
                'operation_key_hash' => hash('sha256', $actor->id.'|'.$key),
                'request_hash' => PlanConfiguration::hash($input),
                'applied_at' => $timestamp,
                'created_at' => $timestamp,
            ]);
            $period = SubscriptionPeriod::create($facts + [
                'workspace_id' => $workspaceId,
                'subscription_id' => $subscription->id,
                'entitlement_version_id' => $target->id,
                'policy_version_id' => $policy->id,
                'fallback_version_id' => $kind === 'paid' ? $fallback->id : null,
                'approval_kind' => 'manual',
                'approved_by' => $actor->id,
                'approval_change_id' => $change,
            ]);
            if ($source->kind === 'trial') {
                DB::table('owner_trial_claims')->where('workspace_id', $workspaceId)->where('period_id', $source->id)
                    ->where('state', 'active')->update(['state' => 'used', 'reason_code' => 'operator_manual_assignment']);
            }
            $cycleId = app(SubscriptionLifecycle::class)->suspend($subscription, $source, $target, $now, $change);
            $cycle = DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('uuid', $cycleId)->firstOrFail();
            $transitions->applyParticipation($actor, $workspaceId, $cycle, $preview, $revision + 1, 'manual_assignment', false);
            $subscription->update(['current_period_id' => $period->id, 'materialized_phase' => $kind,
                'approved_extra_seats' => 0, 'revision' => $revision + 1, 'next_boundary_at' => $period->service_ends_at]);
            if ($kind === 'paid') {
                $transitions->audit($actor, $workspaceId, true, 'transition.paid_restored', [
                    'cycleId' => $cycleId, 'subscriptionRevision' => (string) $subscription->revision, 'previewId' => $previewId, 'selectionHash' => $preview['selectionHash'],
                ]);
            }

            return ['type' => 'subscription', 'id' => $subscription->id, 'uuid' => $subscription->uuid, 'before' => $revision, 'after' => $revision + 1,
                'state' => 'applied', 'effectiveAt' => $now->toISOString(), 'cycleId' => $cycleId];
        }, $workspaceId);
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
