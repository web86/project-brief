<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\User;
use InvalidArgumentException;

final class PlatformPolicyCatalog
{
    public function __construct(private readonly PlanCommands $commands, private readonly PlanConfiguration $validator) {}

    public function save(User $actor, ?int $id, ?int $revision, array $configuration, string $key): PlatformPolicyVersion
    {
        $result = $this->commands->run($actor, 'policy.save', $key, compact('id', 'revision', 'configuration'), function () use ($actor, $id, $revision, $configuration): array {
            $policy = $id === null ? null : PlatformPolicyVersion::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($policy ? $policy->state === 'draft' && $policy->revision === $revision : $revision === null, 409);
            $this->validate($configuration);
            if ($policy) {
                $policy->update($configuration + ['revision' => $revision + 1, 'updated_by' => $actor->id]);
            } else {
                $policy = PlatformPolicyVersion::create($configuration + ['number' => (int) PlatformPolicyVersion::max('number') + 1, 'state' => 'draft', 'revision' => 1, 'created_by' => $actor->id]);
            }

            return ['type' => 'platform_policy', 'id' => $policy->id, 'uuid' => $policy->uuid, 'before' => $revision ?? 0, 'after' => $policy->revision];
        });

        return PlatformPolicyVersion::findOrFail($result['id']);
    }

    public function publish(User $actor, int $id, int $revision, string $key): PlatformPolicyVersion
    {
        $result = $this->commands->run($actor, 'policy.publish', $key, compact('id', 'revision'), function () use ($actor, $id, $revision): array {
            $policy = PlatformPolicyVersion::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($policy->state === 'draft' && $policy->revision === $revision, 409);
            $configuration = $this->configuration($policy);
            $this->validate($configuration);
            $targets = PlanVersion::with('plan')->whereIn('id', [$policy->trial_version_id, $policy->fallback_version_id])->get()->keyBy('id');
            if ($targets->count() !== 2 || $targets->contains(fn ($v) => $v->state !== 'published' || $v->plan->status === 'archived') || $targets[$policy->trial_version_id]->access_class !== 'paid' || $targets[$policy->fallback_version_id]->access_class !== 'free') {
                throw new InvalidArgumentException('Published paid trial and free fallback targets are required.');
            }
            $policy->update(['state' => 'published', 'content_hash' => PlanConfiguration::hash($configuration), 'revision' => $revision + 1, 'published_by' => $actor->id, 'published_at' => now('UTC')]);

            return ['type' => 'platform_policy', 'id' => $id, 'uuid' => $policy->uuid, 'before' => $revision, 'after' => $revision + 1];
        });

        return PlatformPolicyVersion::findOrFail($result['id']);
    }

    public function configuration(PlatformPolicyVersion $policy): array
    {
        return $policy->only(['trial_days', 'grace_days', 'trial_version_id', 'fallback_version_id', 'eligibility_json', 'security_termination_json', 'retention_json']);
    }

    private function validate(array $configuration): void
    {
        $this->validator->bounded($configuration);
        $keys = array_keys($configuration);
        sort($keys);
        if ($keys !== ['eligibility_json', 'fallback_version_id', 'grace_days', 'retention_json', 'security_termination_json', 'trial_days', 'trial_version_id'] || ! is_int($configuration['trial_days']) || $configuration['trial_days'] < 1 || $configuration['trial_days'] > 366 || ! is_int($configuration['grace_days']) || $configuration['grace_days'] < 0 || $configuration['grace_days'] > 366 || ! is_int($configuration['trial_version_id']) || ! is_int($configuration['fallback_version_id']) || PlanConfiguration::hash($configuration['eligibility_json']) !== PlanConfiguration::hash(self::eligibility()) || PlanConfiguration::hash($configuration['security_termination_json']) !== PlanConfiguration::hash(['mode' => 'manual_review']) || PlanConfiguration::hash($configuration['retention_json']) !== PlanConfiguration::hash(self::retention())) {
            throw new InvalidArgumentException('Unsupported policy configuration; automatic adapters are unavailable.');
        }
        if (PlanVersion::whereIn('id', [$configuration['trial_version_id'], $configuration['fallback_version_id']])->count() !== 2) {
            throw new InvalidArgumentException('Distinct trial and fallback targets are required.');
        }
    }

    public static function eligibility(): array
    {
        return ['verification' => 'verified_email_or_trusted_provider_with_verified_email', 'owner_once' => true, 'existing_workspace' => 'ineligible'];
    }

    public static function retention(): array
    {
        return array_fill_keys(['active_free', 'suspended_excess', 'closed_abandoned', 'owner_requested'], ['state' => 'not_configured', 'action' => 'keep', 'retention_days' => null, 'warning_offsets' => [], 'backup_policy_reference' => null]);
    }
}
