<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\User;
use App\Services\CanaryRegistration;
use App\Services\RegistrationReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CommercialCanary
{
    private const AGGREGATE = '00000000-0000-4000-8000-000000000001';

    private const CLAIM_LIMIT = 10;

    private const WINDOW_HOURS = 24;

    public function __construct(private readonly PlanCommands $commands, private readonly RegistrationReadiness $readiness) {}

    public function setStopped(User $actor, int $revision, bool $stopped, string $key): array
    {
        if (! $stopped) {
            $this->requireActivationEnabled();
        }

        $action = $stopped ? 'canary.stop' : 'canary.resume';

        return $this->commands->run($actor, $action, $key, compact('revision'), function () use ($revision, $stopped): array {
            $settings = $this->settings($revision);
            $after = $this->advance($settings->revision);

            return ['type' => 'commercial_canary', 'id' => 1, 'uuid' => self::AGGREGATE,
                'before' => $revision, 'after' => $after, 'revision' => $after, 'state' => $stopped ? 'stopped' : 'open'];
        });
    }

    public function admit(User $actor, int $revision, string $claimUuid, string $key): array
    {
        $this->requireActivationEnabled();
        $candidate = DB::table('owner_trial_claims')->where('uuid', $claimUuid)->first();
        abort_unless($candidate, 404);

        return $this->commands->run($actor, 'canary.admit', $key, compact('revision', 'claimUuid'), function () use ($revision, $candidate): array {
            $settings = $this->settings($revision);
            abort_if($this->stopped(), 409, 'canary_stopped');
            abort_unless($this->remainingActivations() > 0, 409, 'canary_limit_reached');
            $pins = $this->pins();
            $claim = $this->pendingClaims()->where('id', $candidate->id)->lockForUpdate()->first();
            abort_unless($claim && $claim->owner_user_id && $claim->workspace_id === $candidate->workspace_id, 409, 'canary_claim_unavailable');
            $period = app(SubscriptionLifecycle::class)->startVerifiedTrial($claim->workspace_id, $claim->owner_user_id);

            return ['type' => 'subscription', 'id' => $period->id, 'uuid' => $period->uuid, 'before' => 0, 'after' => 1,
                'revision' => $this->advance($settings->revision), 'state' => 'admitted', 'pins' => $pins];
        }, $candidate->workspace_id);
    }

    /** Read-only projection. Claim identities, evidence and configuration hashes stay server-side. */
    public function diagnostics(?array $targets): array
    {
        $settings = DB::table('plan_settings')->where('id', 1)->first();
        $retention = $this->readiness->retentionDays();
        $blockers = $this->readiness->commercialBlockers($targets !== null);
        if ($retention === null) {
            $blockers[] = 'claim_retention_unconfigured';
        }
        $pins = null;
        if (! $blockers) {
            try {
                $pins = $this->pins($targets);
            } catch (HttpException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $versions = [];
        foreach (['studio', 'basic'] as $code) {
            $version = $targets[$code] ?? null;
            if ($version?->plan->code !== $code) {
                $version = PlanVersion::where('state', 'published')->whereHas('plan', fn ($query) => $query
                    ->where('code', $code)->where('status', '<>', 'archived'))->latest('number')->first();
            }
            $versions[$code] = $version?->number;
        }
        $policy = $targets['policy'] ?? PlatformPolicyVersion::where('state', 'published')->find($settings?->current_policy_version_id);

        return [
            'studioVersion' => $versions['studio'],
            'basicVersion' => $versions['basic'],
            'policyVersion' => $policy?->number,
            'targetsReady' => $targets !== null,
            'configurationReady' => $pins !== null,
            'retentionDays' => $retention,
            'pendingVerifiedClaims' => DB::table('owner_trial_claims')->where('state', 'pending')->whereNotNull('verified_at')->whereNull('period_id')->count(),
            'activationStopped' => $this->stopped(),
            'admissionEnabled' => $this->activationEnabled(),
            'remainingActivations' => $this->remainingActivations(),
            'reasonCodes' => $blockers,
        ];
    }

    /** Private projection for the primary operator. The write paths revalidate each candidate. */
    public function management(int $page): array
    {
        $now = CarbonImmutable::now('UTC');
        $revision = DB::table('plan_settings')->where('id', 1)->value('revision');
        $claims = DB::table('account_onboardings as onboarding')
            ->leftJoin('owner_trial_claims as claim', 'claim.owner_user_id', '=', 'onboarding.user_id')
            ->leftJoin('workspaces as workspace', 'workspace.id', '=', 'onboarding.workspace_id')
            ->leftJoin('subscription_periods as period', 'period.id', '=', 'claim.period_id')
            ->leftJoin('workspace_subscriptions as subscription', 'subscription.workspace_id', '=', 'claim.workspace_id')
            ->whereIn('onboarding.state', ['email_verification_pending', 'identity_verified', 'workspace_prepared', 'commercial_active'])
            ->select('onboarding.uuid as registration_uuid', 'onboarding.user_id as owner_user_id', 'onboarding.verified_at as email_verified_at',
                'claim.uuid', 'claim.workspace_id', 'claim.verified_at', 'claim.state', 'workspace.name as workspace_name',
                'workspace.uuid as workspace_uuid', 'period.uuid as period_uuid', 'period.starts_at', 'period.service_ends_at', 'subscription.id as subscription_id')
            ->orderByDesc('onboarding.user_id')->simplePaginate(20, page: $page);
        $owners = User::whereIn('id', $claims->getCollection()->pluck('owner_user_id')->filter())
            ->get(['id', 'name', 'email', 'email_verified_at', 'two_factor_secret', 'two_factor_confirmed_at', 'recovery_codes_acknowledged_at'])
            ->keyBy('id');
        $pending = $this->pendingClaims()->whereIn('uuid', $claims->getCollection()->pluck('uuid')->filter())->pluck('uuid')->flip();
        $lifecycle = app(SubscriptionLifecycle::class);
        $clock = app(SubscriptionClock::class);

        return [
            'revision' => $revision ? (string) $revision : null,
            'serverTime' => $now->toISOString(),
            'inviteEnabled' => app(CanaryRegistration::class)->canIssue(),
            'claimLimit' => self::CLAIM_LIMIT,
            'windowHours' => self::WINDOW_HOURS,
            'admittedOwners' => DB::table('owner_trial_claims')->whereNotNull('period_id')->count(),
            'page' => $claims->currentPage(),
            'hasMore' => $claims->hasMorePages(),
            'owners' => $claims->getCollection()->map(function (object $claim) use ($owners, $pending, $lifecycle, $clock, $now): array {
                $owner = $owners->get($claim->owner_user_id);
                $verified = $claim->verified_at ? CarbonImmutable::parse($claim->verified_at, 'UTC') : null;
                $end = $verified ? $clock->period('trial', $verified, 7)['service_ends_at'] : null;
                $eligible = $pending->has($claim->uuid) && ! $claim->subscription_id
                    && $owner && $lifecycle->verifiedTrialOwnerReady($owner, $claim->workspace_id);

                return [
                    'id' => $claim->registration_uuid,
                    'claimId' => $claim->uuid,
                    'ownerName' => $owner?->name,
                    'email' => $owner?->email,
                    'workspace' => ['id' => $claim->workspace_uuid, 'name' => $claim->workspace_name],
                    'verifiedAt' => $verified?->toISOString(),
                    'emailVerified' => (bool) $claim->email_verified_at && (bool) $owner?->email_verified_at,
                    'state' => $claim->state,
                    'eligible' => $eligible,
                    'trialEndsAt' => $end?->toISOString(),
                    'windowClosed' => $end !== null && ($verified > $now || $end <= $now),
                    'period' => $claim->period_uuid ? [
                        'id' => $claim->period_uuid,
                        'startsAt' => CarbonImmutable::parse($claim->starts_at, 'UTC')->toISOString(),
                        'endsAt' => CarbonImmutable::parse($claim->service_ends_at, 'UTC')->toISOString(),
                    ] : null,
                ];
            })->all(),
        ];
    }

    private function activationEnabled(): bool
    {
        return config('onboarding.trial_activation_requested') === true && ! config('demo.enabled', false);
    }

    private function requireActivationEnabled(): void
    {
        abort_unless($this->activationEnabled(), 403, 'canary_disabled');
    }

    private function stopped(): bool
    {
        return DB::table('plan_events')->where('aggregate_type', 'commercial_canary')
            ->whereIn('action', ['canary.approve', 'canary.stop', 'canary.resume'])->latest('id')->value('action') === 'canary.stop';
    }

    private function remainingActivations(): int
    {
        $used = DB::table('plan_events')->where('action', 'canary.admit')->where('result', 'applied')
            ->where('created_at', '>', CarbonImmutable::now('UTC')->subHours(self::WINDOW_HOURS))
            ->distinct()->count('aggregate_uuid');

        return max(0, self::CLAIM_LIMIT - $used);
    }

    private function pendingClaims(): Builder
    {
        $cutoff = DB::table('plan_inventory_cohorts')->where('id', 1)->value('workspace_cutoff_id');
        $now = CarbonImmutable::now('UTC');

        return DB::table('owner_trial_claims')->where('state', 'pending')
            ->where('verified_at', '>', $now->subSeconds(604800)->format('Y-m-d H:i:s.u'))
            ->where('verified_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->whereNull('period_id')->where('workspace_id', '>', $cutoff)->where('reason_code', 'activation_unavailable');
    }

    private function settings(int $revision): object
    {
        $settings = DB::table('plan_settings')->where('id', 1)->lockForUpdate()->first();
        abort_unless($settings && (int) $settings->revision === $revision, 409, 'plan_revision_conflict');

        return $settings;
    }

    private function pins(?array $targets = null): array
    {
        $targets ??= $this->readiness->targets();
        $machineBlockers = $this->readiness->commercialBlockers($targets !== null);
        abort_unless($targets && ! $machineBlockers && $this->readiness->retentionDays() !== null, 409, 'commercial_readiness_required');
        abort_unless($targets['studio']->plan->code === 'studio' && $targets['basic']->plan->code === 'basic', 409, 'commercial_targets_required');
        $basic = app(PlanCatalog::class)->configuration($targets['basic']);
        abort_unless($basic['developer_seats']['hard_max'] === 1, 409, 'commercial_basic_contract_required');
        $required = [
            'projects.active.max' => 1,
            'clients.max' => 3,
            'agents.active.max' => 0,
            'agents.enabled' => false,
            'api.agents.enabled' => false,
            'api.external.enabled' => false,
            'webhooks.enabled' => false,
            'sso.enabled' => false,
        ];
        foreach ($required as $key => $value) {
            abort_unless(($basic['entitlements'][$key]['value'] ?? null) === $value, 409, 'commercial_basic_contract_required');
        }

        return array_map(fn ($target) => ['id' => (int) $target->id, 'hash' => $target->content_hash], $targets);
    }

    private function advance(int $revision): int
    {
        DB::table('plan_settings')->where('id', 1)->update(['revision' => $revision + 1]);

        return $revision + 1;
    }
}
