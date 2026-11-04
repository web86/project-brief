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

    private const EVIDENCE = ['lifecycle', 'execution_barriers', 'rollback', 'notification_delivery', 'trial_claim_policy'];

    private const CLAIM_LIMIT = 10;

    private const PERMIT_HOURS = 24;

    public function __construct(private readonly PlanCommands $commands, private readonly RegistrationReadiness $readiness) {}

    /** Approval is an explicit, short-lived allowlist of prepared claims. It does not open registration. */
    public function approve(User $actor, int $revision, array $claims, array $evidence, string $key): array
    {
        sort($claims);
        abort_unless(count($claims) >= 1 && count($claims) <= self::CLAIM_LIMIT && count(array_unique($claims)) === count($claims), 422);
        abort_unless(count($evidence) === count(self::EVIDENCE) && ! array_diff(self::EVIDENCE, array_keys($evidence)), 422);
        foreach ($evidence as $digest) {
            abort_unless(is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest), 422);
        }

        return $this->commands->run($actor, 'canary.approve', $key, compact('revision', 'claims', 'evidence'), function (object $authority) use ($revision, $claims, $evidence): array {
            $settings = $this->settings($revision);
            $pins = $this->pins();
            $eligible = $this->pendingClaims()->whereIn('uuid', $claims)->count();
            abort_unless($eligible === count($claims), 409, 'canary_claim_unavailable');
            $after = $this->advance($settings->revision);

            return $this->outcome($revision, $after) + [
                'state' => 'approved', 'authorityRevision' => (int) $authority->revision,
                'claims' => $claims, 'pins' => $pins, 'evidence' => $evidence,
                'expiresAt' => CarbonImmutable::now('UTC')->addHours(self::PERMIT_HOURS)->toISOString(),
            ];
        });
    }

    public function stop(User $actor, int $revision, string $key): array
    {
        return $this->commands->run($actor, 'canary.stop', $key, compact('revision'), function () use ($revision): array {
            $settings = $this->settings($revision);

            return $this->outcome($revision, $this->advance($settings->revision)) + ['state' => 'stopped'];
        });
    }

    public function admit(User $actor, int $revision, string $claimUuid, string $key): array
    {
        abort_unless(config('onboarding.trial_activation_requested') === true && ! config('demo.enabled', false), 403, 'canary_disabled');
        $candidate = DB::table('owner_trial_claims')->where('uuid', $claimUuid)->first();
        abort_unless($candidate, 404);

        return $this->commands->run($actor, 'canary.admit', $key, compact('revision', 'claimUuid'), function (object $authority) use ($revision, $claimUuid, $candidate): array {
            $this->settings($revision);
            $permit = $this->latestPermit();
            abort_unless(($permit['state'] ?? null) === 'approved' && $permit['after'] === $revision
                && $permit['authorityRevision'] === (int) $authority->revision
                && in_array($claimUuid, $permit['claims'], true)
                && CarbonImmutable::parse($permit['expiresAt'], 'UTC')->isFuture()
                && $permit['pins'] === $this->pins(), 409, 'canary_permit_unavailable');
            $cutoff = DB::table('plan_inventory_cohorts')->where('id', 1)->value('workspace_cutoff_id');
            abort_unless($candidate->owner_user_id && $candidate->workspace_id > $cutoff, 409, 'canary_claim_unavailable');
            $period = app(SubscriptionLifecycle::class)->startVerifiedTrial($candidate->workspace_id, $candidate->owner_user_id);

            return ['type' => 'subscription', 'id' => $period->id, 'uuid' => $period->uuid, 'before' => 0, 'after' => 1];
        }, $candidate->workspace_id);
    }

    /** Read-only projection. Claim identities, evidence and configuration hashes stay server-side. */
    public function diagnostics(?array $targets): array
    {
        $settings = DB::table('plan_settings')->where('id', 1)->first();
        $authority = DB::table('platform_plan_authority')->where('id', 1)->value('revision');
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

        $permit = $this->latestPermit();
        $approval = match (true) {
            ($permit['state'] ?? null) === 'stopped' => 'stopped',
            ($permit['state'] ?? null) !== 'approved' => 'missing',
            ! CarbonImmutable::parse($permit['expiresAt'], 'UTC')->isFuture() => 'expired',
            ! $pins || $permit['pins'] !== $pins || $permit['after'] !== (int) $settings?->revision
                || $permit['authorityRevision'] !== (int) $authority => 'stale',
            default => 'approved',
        };

        return [
            'studioVersion' => $versions['studio'],
            'basicVersion' => $versions['basic'],
            'policyVersion' => $policy?->number,
            'targetsReady' => $targets !== null,
            'configurationReady' => $pins !== null,
            'retentionDays' => $retention,
            'pendingVerifiedClaims' => DB::table('owner_trial_claims')->where('state', 'pending')->whereNotNull('verified_at')->whereNull('period_id')->count(),
            'operatorApproval' => $approval,
            'approvedPendingClaims' => $approval === 'approved' ? $this->pendingClaims()->whereIn('uuid', $permit['claims'])->count() : 0,
            'admissionEnabled' => config('onboarding.trial_activation_requested') === true && ! config('demo.enabled', false),
            'reasonCodes' => $blockers,
        ];
    }

    /** Private projection for the primary operator. The write paths revalidate each candidate. */
    public function management(array $diagnostics, int $page): array
    {
        $now = CarbonImmutable::now('UTC');
        $permit = $this->latestPermit();
        $allowlist = $diagnostics['operatorApproval'] === 'approved' ? $permit['claims'] : [];
        $revision = DB::table('plan_settings')->where('id', 1)->value('revision');
        $claims = DB::table('owner_trial_claims as claim')
            ->join('workspaces as workspace', 'workspace.id', '=', 'claim.workspace_id')
            ->leftJoin('subscription_periods as period', 'period.id', '=', 'claim.period_id')
            ->leftJoin('workspace_subscriptions as subscription', 'subscription.workspace_id', '=', 'claim.workspace_id')
            ->whereNotNull('claim.verified_at')
            ->select('claim.uuid', 'claim.owner_user_id', 'claim.workspace_id', 'claim.verified_at', 'claim.state', 'workspace.name as workspace_name',
                'workspace.uuid as workspace_uuid', 'period.uuid as period_uuid', 'period.starts_at', 'period.service_ends_at', 'subscription.id as subscription_id')
            ->orderByDesc('claim.id')->simplePaginate(20, page: $page);
        $owners = User::whereIn('id', $claims->getCollection()->pluck('owner_user_id')->filter())
            ->get(['id', 'name', 'email', 'email_verified_at', 'two_factor_secret', 'two_factor_confirmed_at', 'recovery_codes_acknowledged_at'])
            ->keyBy('id');
        $pending = $this->pendingClaims()->whereIn('uuid', $claims->getCollection()->pluck('uuid'))->pluck('uuid')->flip();
        $lifecycle = app(SubscriptionLifecycle::class);
        $clock = app(SubscriptionClock::class);

        return [
            'revision' => $revision ? (string) $revision : null,
            'serverTime' => $now->toISOString(),
            'inviteEnabled' => app(CanaryRegistration::class)->canIssue(),
            'permitExpiresAt' => $permit['expiresAt'] ?? null,
            'claimLimit' => self::CLAIM_LIMIT,
            'permitHours' => self::PERMIT_HOURS,
            'admittedOwners' => DB::table('owner_trial_claims')->whereNotNull('period_id')->count(),
            'page' => $claims->currentPage(),
            'hasMore' => $claims->hasMorePages(),
            'owners' => $claims->getCollection()->map(function (object $claim) use ($owners, $pending, $lifecycle, $clock, $now, $allowlist): array {
                $owner = $owners->get($claim->owner_user_id);
                $verified = CarbonImmutable::parse($claim->verified_at, 'UTC');
                $end = $clock->period('trial', $verified, 7)['service_ends_at'];
                $eligible = $pending->has($claim->uuid) && ! $claim->subscription_id
                    && $owner && $lifecycle->verifiedTrialOwnerReady($owner, $claim->workspace_id);

                return [
                    'claimId' => $claim->uuid,
                    'ownerName' => $owner?->name,
                    'email' => $owner?->email_verified_at ? $owner->email : null,
                    'workspace' => ['id' => $claim->workspace_uuid, 'name' => $claim->workspace_name],
                    'verifiedAt' => $verified->toISOString(),
                    'state' => $claim->state,
                    'eligible' => $eligible,
                    'allowlisted' => in_array($claim->uuid, $allowlist, true),
                    'trialEndsAt' => $end->toISOString(),
                    'windowClosed' => $verified > $now || $end <= $now,
                    'period' => $claim->period_uuid ? [
                        'id' => $claim->period_uuid,
                        'startsAt' => CarbonImmutable::parse($claim->starts_at, 'UTC')->toISOString(),
                        'endsAt' => CarbonImmutable::parse($claim->service_ends_at, 'UTC')->toISOString(),
                    ] : null,
                ];
            })->all(),
        ];
    }

    private function latestPermit(): array
    {
        $row = DB::table('plan_events')->where('aggregate_type', 'commercial_canary')
            ->whereIn('action', ['canary.approve', 'canary.stop'])->latest('id')->first();

        return $row ? json_decode($row->result_json, true, 16, JSON_THROW_ON_ERROR) : [];
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

    private function outcome(int $before, int $after): array
    {
        return ['type' => 'commercial_canary', 'id' => 1, 'uuid' => self::AGGREGATE, 'before' => $before, 'after' => $after];
    }
}
