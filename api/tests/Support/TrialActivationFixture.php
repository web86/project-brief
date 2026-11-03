<?php

namespace Tests\Support;

use App\Models\Plans\SubscriptionPeriod;
use App\Models\Plans\WorkspaceSubscription;
use App\Models\User;
use App\Services\AuthOnboarding;
use App\Services\IntegrationWrites;
use App\Services\TrialEligibility;
use Illuminate\Support\Facades\DB;

/** Test-only adapter. This file is excluded from every production ZIP and has no HTTP route. */
final class TrialActivationFixture
{
    public static function activate(User $owner, ?int $workspaceId = null): object
    {
        $database = DB::getDatabaseName();
        if (! app()->environment('testing') || ! app()->runningInConsole() || ! ($database === ':memory:' || $database === 'projectbrief_agent_ux_full_qa' || str_contains($database, '/tmp/'))) {
            throw new \LogicException('Isolated test database only.');
        }
        $workspaceId ??= DB::table('account_onboardings')->where('user_id', $owner->id)->value('workspace_id');

        return DB::transaction(function () use ($owner, $workspaceId): object {
            IntegrationWrites::lockWorkspace($workspaceId);
            $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $claim = DB::table('owner_trial_claims')->where('owner_user_id', $owner->id)->lockForUpdate()->first();
            abort_unless($claim && $locked->email_verified_at && (int) $claim->workspace_id === $workspaceId, 409);
            if ($claim->period_id) {
                return DB::table('subscription_periods')->where('id', $claim->period_id)->first();
            }
            $proposal = app(TrialEligibility::class)->proposedTrial($claim);
            abort_if(DB::table('workspace_subscriptions')->where('workspace_id', $workspaceId)->exists(), 409);
            $subscription = WorkspaceSubscription::create(['workspace_id' => $workspaceId, 'revision' => 1, 'enforcement_mode' => 'observe', 'materialized_phase' => 'trial', 'next_boundary_at' => $proposal['period']['service_ends_at']]);
            $period = SubscriptionPeriod::create($proposal['period'] + ['workspace_id' => $workspaceId, 'subscription_id' => $subscription->id, 'entitlement_version_id' => $proposal['versionId'], 'policy_version_id' => $proposal['policyId'], 'fallback_version_id' => $proposal['fallbackId'], 'approval_kind' => 'trial_verification']);
            $subscription->update(['current_period_id' => $period->id]);
            DB::table('owner_trial_claims')->where('id', $claim->id)->update(['period_id' => $period->id, 'state' => 'active', 'reason_code' => 'isolated_test_activation', 'starts_at' => $period->starts_at->format('Y-m-d H:i:s.u'), 'ends_at' => $period->service_ends_at->format('Y-m-d H:i:s.u')]);
            app(AuthOnboarding::class)->audit($locked, 'trial.fixture_activated', ['claimId' => $claim->uuid, 'periodId' => $period->uuid]);

            return DB::table('subscription_periods')->where('id', $period->id)->first();
        }, 3);
    }
}
