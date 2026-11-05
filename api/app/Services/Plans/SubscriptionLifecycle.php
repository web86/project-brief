<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Plans\SubscriptionPeriod;
use App\Models\Plans\WorkspaceSubscription;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\IntegrationWrites;
use App\Services\TrialEligibility;
use App\Services\WorkspaceAgreements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionLifecycle
{
    public const SUSPENSION_REASONS = ['subscription_expired', 'manual_assignment', 'initial_manual_assignment'];

    public const EXECUTION_GROUPS = ['agent', 'webhook', 'api', 'sso'];

    public function __construct(private readonly SubscriptionClock $clock) {}

    /** Internal executor for a verified claim after a separate commercial admission decision. */
    public function startVerifiedTrial(int $workspaceId, int $ownerId): SubscriptionPeriod
    {
        return DB::transaction(function () use ($workspaceId, $ownerId): SubscriptionPeriod {
            IntegrationWrites::lockWorkspace($workspaceId);
            $claim = DB::table('owner_trial_claims')->where('workspace_id', $workspaceId)
                ->where('owner_user_id', $ownerId)->lockForUpdate()->first();
            abort_unless($claim, 409, 'trial_claim_required');

            if ($claim->period_id !== null) {
                return SubscriptionPeriod::where('workspace_id', $workspaceId)->where('kind', 'trial')->findOrFail($claim->period_id);
            }

            $owner = User::findOrFail($ownerId);
            abort_unless($this->verifiedTrialOwnerReady($owner, $workspaceId), 403, 'canary_owner_onboarding_required');
            abort_if(WorkspaceSubscription::where('workspace_id', $workspaceId)->exists(), 409, 'subscription_already_exists');

            $proposal = app(TrialEligibility::class)->proposedTrial($claim);
            $facts = $proposal['period'];
            $now = CarbonImmutable::now('UTC');
            abort_if($now < $facts['starts_at'] || $now >= $facts['service_ends_at'], 409, 'trial_activation_window_closed');
            $policy = PlatformPolicyVersion::findOrFail($proposal['policyId']);
            abort_unless($policy->grace_days === 7, 409, 'commercial_policy_required');

            $subscription = WorkspaceSubscription::create([
                'workspace_id' => $workspaceId,
                'enforcement_mode' => 'enforce',
                'revision' => 0,
            ]);
            $period = SubscriptionPeriod::create($facts + [
                'workspace_id' => $workspaceId,
                'subscription_id' => $subscription->id,
                'entitlement_version_id' => $proposal['versionId'],
                'policy_version_id' => $proposal['policyId'],
                'fallback_version_id' => $proposal['fallbackId'],
                'approval_kind' => 'trial_verification',
            ]);

            DB::table('owner_trial_claims')->where('id', $claim->id)->update([
                'period_id' => $period->id,
                'state' => 'active',
                'reason_code' => null,
                'starts_at' => $facts['starts_at']->format('Y-m-d H:i:s.u'),
                'ends_at' => $facts['service_ends_at']->format('Y-m-d H:i:s.u'),
            ]);
            $this->completeOnboarding($ownerId, $workspaceId);
            $this->advance($subscription, 'trial', $period, $facts['starts_at'], $facts['service_ends_at']);

            return $period;
        }, 3);
    }

    public function verifiedTrialOwnerReady(User $owner, int $workspaceId): bool
    {
        if (! $owner->email_verified_at || ! $owner->hasEnabledTwoFactorAuthentication() || ! $owner->recovery_codes_acknowledged_at) {
            return false;
        }

        $member = WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $owner->id)
            ->where('status', 'active')->where('role', 'owner')->first();

        return $member && ! app(WorkspaceAgreements::class)->required($member)
            && $this->preparedOnboarding($owner->id, $workspaceId)->exists();
    }

    public function initialAssignmentOwner(int $workspaceId, bool $lock = false): ?User
    {
        $owners = WorkspaceMember::where('workspace_id', $workspaceId)->where('status', 'active')
            ->where('role', 'owner')->limit(2)->pluck('user_id');
        if ($owners->count() !== 1) {
            return null;
        }

        $query = User::whereKey($owners->sole());
        $owner = ($lock ? $query->lockForUpdate() : $query)->first();

        return $owner && $this->verifiedTrialOwnerReady($owner, $workspaceId) ? $owner : null;
    }

    public function completeOnboarding(int $ownerId, int $workspaceId): void
    {
        abort_unless($this->preparedOnboarding($ownerId, $workspaceId)->update([
            'state' => 'commercial_active',
            'revision' => DB::raw('revision + 1'),
            'updated_at' => now('UTC'),
        ]) === 1, 409);
    }

    private function preparedOnboarding(int $ownerId, int $workspaceId): Builder
    {
        return DB::table('account_onboardings')->where('workspace_id', $workspaceId)
            ->where('user_id', $ownerId)->where('state', 'workspace_prepared')->whereNotNull('verified_at');
    }

    /** Run before a protected business transaction so a denial cannot roll back the expiry. */
    public function catchUp(int $workspaceId): void
    {
        DB::transaction(function () use ($workspaceId): void {
            IntegrationWrites::lockWorkspace($workspaceId);
            $subscription = WorkspaceSubscription::where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if (! $subscription || ($subscription->enforcement_mode !== 'enforce'
                && ! DB::table('workspace_execution_epochs')->where('workspace_id', $workspaceId)->exists())) {
                return;
            }

            $period = $subscription->currentPeriod()->firstOrFail();
            if (! in_array($period->kind, ['trial', 'paid'], true)) {
                return;
            }

            $now = CarbonImmutable::now('UTC');
            if ($period->kind === 'paid' && $now >= $period->service_ends_at) {
                $period = $this->continuePaid($subscription, $period, $now);
            }
            $phase = $this->clock->phase($period->only(['kind', 'starts_at', 'service_ends_at', 'grace_ends_at']), $now)['phase'];
            if ($period->kind === 'paid' && in_array($phase, ['grace', 'fallback_required'], true) && $subscription->materialized_phase === 'paid') {
                $this->advance($subscription, 'grace', $period, $period->service_ends_at, $period->grace_ends_at);
            }

            if ($phase === 'fallback_required') {
                $this->fallback($subscription, $period);
            }
        }, 3);
    }

    private function continuePaid(WorkspaceSubscription $subscription, SubscriptionPeriod $source, CarbonImmutable $now): SubscriptionPeriod
    {
        $boundary = $source->service_ends_at->utc()->format('Y-m-d H:i:s.u');
        $change = DB::table('subscription_changes')->where('workspace_id', $subscription->workspace_id)
            ->where('subscription_id', $subscription->id)->where('kind', 'renew')
            ->where('state', 'scheduled')->where('effective_at', $boundary)->lockForUpdate()->first();
        if (! $change) {
            return $source;
        }
        $period = SubscriptionPeriod::where('workspace_id', $subscription->workspace_id)->where('subscription_id', $subscription->id)
            ->where('approval_change_id', $change->id)->where('kind', 'paid')->firstOrFail();
        abort_unless($period->starts_at->equalTo($source->service_ends_at)
            && (int) $period->entitlement_version_id === (int) $source->entitlement_version_id, 409, 'renewal_pin_invalid');
        DB::table('subscription_changes')->where('id', $change->id)->update(['state' => 'applied', 'applied_at' => $now->format('Y-m-d H:i:s.u')]);
        $this->advance($subscription, 'paid', $period, $period->starts_at, $period->service_ends_at);

        return $period;
    }

    private function fallback(WorkspaceSubscription $subscription, SubscriptionPeriod $source): void
    {
        $policy = PlatformPolicyVersion::where('state', 'published')->findOrFail($source->policy_version_id);
        abort_unless((int) $policy->fallback_version_id === (int) $source->fallback_version_id, 409, 'fallback_pin_invalid');
        $version = PlanVersion::where('state', 'published')->where('access_class', 'free')->findOrFail($source->fallback_version_id);
        $at = $source->kind === 'paid' ? $source->grace_ends_at : $source->service_ends_at;
        $workspaceId = $subscription->workspace_id;
        $fallback = SubscriptionPeriod::create($this->clock->period('free', $at) + [
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscription->id,
            'entitlement_version_id' => $version->id,
            'policy_version_id' => $policy->id,
            'approval_kind' => 'system',
        ]);

        $cycle = $this->suspend($subscription, $source, $version, $at);

        if ($source->kind === 'trial') {
            DB::table('owner_trial_claims')->where('workspace_id', $workspaceId)->where('period_id', $source->id)
                ->where('state', 'active')->update(['state' => 'expired']);
        }

        $subscription->approved_extra_seats = 0;
        $this->advance($subscription, 'free', $fallback, $at, null, $cycle);
    }

    public function suspend(WorkspaceSubscription $subscription, SubscriptionPeriod $source, PlanVersion $version, CarbonImmutable $at, ?int $changeId = null, bool $initial = false): string
    {
        $workspaceId = $subscription->workspace_id;
        $boundary = $at->utc()->format('Y-m-d H:i:s.u');
        $cycle = (string) Str::uuid();
        DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')
            ->whereIn('reason_code', self::SUSPENSION_REASONS)->update(['state' => 'resolved', 'revision' => DB::raw('revision + 1')]);
        DB::table('workspace_suspension_cycles')->insert([
            'uuid' => $cycle,
            'workspace_id' => $workspaceId,
            'source_period_id' => $source->id,
            'source_change_id' => $changeId,
            'effective_at' => $boundary,
            'reason_code' => match (true) {
                $initial => 'initial_manual_assignment',
                $changeId !== null => 'manual_assignment',
                default => 'subscription_expired',
            },
            'target_version_id' => $version->id,
            'state' => 'open',
            'resource_high_watermarks_json' => json_encode($this->highWatermarks($workspaceId), JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'),
        ]);

        foreach (self::EXECUTION_GROUPS as $group) {
            $key = ['workspace_id' => $workspaceId, 'feature_group' => $group];
            $previous = DB::table('workspace_execution_epochs')->where($key)->lockForUpdate()->first();
            DB::table('workspace_execution_epochs')->updateOrInsert($key, [
                'epoch' => ($previous->epoch ?? 0) + 1,
                'revision' => ($previous->revision ?? 0) + 1,
                'state' => 'blocked',
                'blocked_at' => $boundary,
                'resume_at' => null,
                'confirmation_change_id' => null,
            ]);
        }

        return $cycle;
    }

    private function highWatermarks(int $workspaceId): array
    {
        $tables = [
            'project' => 'projects',
            'workspace_member' => 'workspace_members',
            'external_agent' => 'external_agents',
            'integration_token' => 'integration_tokens',
            'webhook_endpoint' => 'webhook_endpoints',
            'integration_source' => 'integration_sources',
        ];
        $result = [];
        foreach ($tables as $kind => $table) {
            $result[$kind] = (int) DB::table($table)->where('workspace_id', $workspaceId)->orderByDesc('id')->lockForUpdate()->value('id');
        }
        $result['project_client'] = (int) DB::table('project_clients')->orderByDesc('id')->value('id');

        return $result;
    }

    private function advance(WorkspaceSubscription $subscription, string $phase, SubscriptionPeriod $period, CarbonImmutable $at, ?CarbonImmutable $next, ?string $cycle = null): void
    {
        $before = $subscription->revision;
        $subscription->update([
            'current_period_id' => $period->id,
            'materialized_phase' => $phase,
            'next_boundary_at' => $next,
            'revision' => $before + 1,
        ]);

        $metadata = json_encode(['period' => $period->uuid, 'phase' => $phase, 'effectiveAt' => $at->toISOString(), 'cycle' => $cycle], JSON_THROW_ON_ERROR);
        DB::table('plan_events')->insert([
            'uuid' => (string) Str::uuid(),
            'aggregate_type' => 'subscription',
            'aggregate_uuid' => $subscription->uuid,
            'workspace_id' => $subscription->workspace_id,
            'actor_kind' => 'system',
            'action' => 'subscription.'.$phase,
            'result' => 'applied',
            'before_revision' => $before,
            'after_revision' => $before + 1,
            'diff_json' => $metadata,
            'created_at' => now('UTC'),
        ]);
        DB::table('plan_notification_outbox')->insert([
            'workspace_id' => $subscription->workspace_id,
            'period_uuid' => $period->uuid,
            'cycle_uuid' => $cycle,
            'notice_kind' => 'subscription_'.$phase,
            'due_at' => $at->utc()->format('Y-m-d H:i:s.u'),
            'delivery_state' => 'pending',
            'dedupe_hash' => hash('sha256', $period->uuid.'|'.$phase),
            'metadata_json' => $metadata,
            'created_at' => now('UTC'),
        ]);
    }
}
