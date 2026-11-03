<?php

namespace App\Services\Plans;

use App\Models\User;
use App\Services\RegistrationReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CommercialCanary
{
    private const AGGREGATE = '00000000-0000-4000-8000-000000000001';

    private const EVIDENCE = ['lifecycle', 'execution_barriers', 'rollback', 'notification_delivery', 'trial_claim_policy'];

    public function __construct(private readonly PlanCommands $commands, private readonly RegistrationReadiness $readiness) {}

    /** Approval is an explicit, short-lived allowlist of prepared claims. It does not open registration. */
    public function approve(User $actor, int $revision, array $claims, array $evidence, string $key): array
    {
        sort($claims);
        abort_unless(count($claims) >= 1 && count($claims) <= 10 && count(array_unique($claims)) === count($claims), 422);
        abort_unless(count($evidence) === count(self::EVIDENCE) && ! array_diff(self::EVIDENCE, array_keys($evidence)), 422);
        foreach ($evidence as $digest) {
            abort_unless(is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest), 422);
        }

        return $this->commands->run($actor, 'canary.approve', $key, compact('revision', 'claims', 'evidence'), function (object $authority) use ($revision, $claims, $evidence): array {
            $settings = $this->settings($revision);
            $pins = $this->pins();
            $cutoff = DB::table('plan_inventory_cohorts')->where('id', 1)->value('workspace_cutoff_id');
            $now = CarbonImmutable::now('UTC');
            $eligible = DB::table('owner_trial_claims')->whereIn('uuid', $claims)->where('state', 'pending')
                ->where('verified_at', '>', $now->subSeconds(604800)->format('Y-m-d H:i:s.u'))
                ->where('verified_at', '<=', $now->format('Y-m-d H:i:s.u'))
                ->whereNull('period_id')->where('workspace_id', '>', $cutoff)->where('reason_code', 'activation_unavailable')->count();
            abort_unless($eligible === count($claims), 409, 'canary_claim_unavailable');
            $after = $this->advance($settings->revision);

            return $this->outcome($revision, $after) + [
                'state' => 'approved', 'authorityRevision' => (int) $authority->revision,
                'claims' => $claims, 'pins' => $pins, 'evidence' => $evidence,
                'expiresAt' => CarbonImmutable::now('UTC')->addHours(24)->toISOString(),
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
            $row = DB::table('plan_events')->where('aggregate_type', 'commercial_canary')
                ->whereIn('action', ['canary.approve', 'canary.stop'])->latest('id')->first();
            $permit = $row ? json_decode($row->result_json, true, 16, JSON_THROW_ON_ERROR) : [];
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

    private function settings(int $revision): object
    {
        $settings = DB::table('plan_settings')->where('id', 1)->lockForUpdate()->first();
        abort_unless($settings && (int) $settings->revision === $revision, 409, 'plan_revision_conflict');

        return $settings;
    }

    private function pins(): array
    {
        $targets = $this->readiness->targets();
        $machineBlockers = array_diff($this->readiness->commercialBlockers($targets !== null), [
            'phase_4b2_lifecycle_required', 'execution_rearm_review_required', 'rollback_verification_required', 'notification_delivery_verification_required',
        ]);
        $retention = filter_var(config('onboarding.subject_retention_days'), FILTER_VALIDATE_INT);
        abort_unless($targets && ! $machineBlockers && $retention !== false && $retention >= 1 && $retention <= 3650, 409, 'commercial_readiness_required');
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
