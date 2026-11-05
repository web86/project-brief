<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class PlanInput
{
    public static function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown input fields.');
        }
        app(PlanConfiguration::class)->bounded($input);
    }

    public static function revision(Request $request, bool $allowZero = false): int
    {
        $match = $request->header('If-Match');
        abort_if($match === null, 428);
        if ($allowZero && $match === '"revision:0"') {
            return 0;
        }

        abort_unless(preg_match('/^"revision:([1-9][0-9]{0,14})"$/D', $match, $parts), 422);

        return (int) $parts[1];
    }

    public static function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422);

        return $key;
    }

    public function configuration(array $input): array
    {
        self::only($input, ['accessClass', 'visibility', 'translations', 'price', 'developerSeats', 'entitlements']);
        abort_if(($input['accessClass'] ?? '') === 'compatibility', 422);
        $seats = $input['developerSeats'] ?? [];
        self::only($seats, ['included', 'hardMax', 'allowExtra']);
        $price = $input['price'] ?? [];
        self::only($price, ['mode', 'amountMinor', 'currency', 'scale', 'interval']);
        $values = [];
        foreach ($input['entitlements'] ?? [] as $item) {
            self::only($item, ['key', 'scope', 'value']);
            $key = $item['key'] ?? '';
            if (! is_string($key) || isset($values[$key]) || ($item['scope'] ?? '') !== 'workspace') {
                throw new InvalidArgumentException('Invalid entitlement scope or duplicate key.');
            }
            $values[$key] = $item['value'] ?? null;
        }

        return app(PlanConfiguration::class)->validate(['access_class' => $input['accessClass'] ?? null, 'visibility' => $input['visibility'] ?? null, 'translations' => $input['translations'] ?? [], 'price' => ['mode' => $price['mode'] ?? null, 'amount_minor' => $price['amountMinor'] ?? null, 'currency' => $price['currency'] ?? null, 'scale' => $price['scale'] ?? null, 'interval' => $price['interval'] ?? null], 'developer_seats' => ['included' => $seats['included'] ?? null, 'hard_max' => $seats['hardMax'] ?? null, 'allow_extra' => $seats['allowExtra'] ?? null], 'entitlements' => $values]);
    }

    public function version(PlanVersion $version): array
    {
        $config = app(PlanCatalog::class)->configuration($version);
        $values = [];
        foreach ($config['entitlements'] as $key => $value) {
            $values[] = ['key' => $key, 'scope' => 'workspace', 'value' => $value];
        }

        return ['id' => $version->uuid, 'planId' => $version->plan->uuid, 'number' => $version->number, 'revision' => (string) $version->revision, 'state' => $version->state, 'contentHash' => $version->content_hash, 'configuration' => ['accessClass' => $config['access_class'], 'visibility' => $config['visibility'], 'translations' => $config['translations'], 'price' => ['mode' => $config['price']['mode'], 'amountMinor' => $config['price']['amount_minor'], 'currency' => $config['price']['currency'], 'scale' => $config['price']['scale'], 'interval' => $config['price']['interval']], 'developerSeats' => ['included' => $config['developer_seats']['included'], 'hardMax' => $config['developer_seats']['hard_max'], 'allowExtra' => $config['developer_seats']['allow_extra']], 'entitlements' => $values]];
    }

    public function policy(array $input): array
    {
        self::only($input, ['trialDays', 'graceDays', 'trialVersionId', 'fallbackVersionId', 'eligibility', 'securityTermination', 'retention']);
        $trial = PlanVersion::with('plan')->where('uuid', $input['trialVersionId'] ?? '')->firstOrFail();
        $fallback = PlanVersion::with('plan')->where('uuid', $input['fallbackVersionId'] ?? '')->firstOrFail();
        abort_unless($trial->state === 'published' && $fallback->state === 'published' && $trial->access_class === 'paid' && $fallback->access_class === 'free' && $trial->plan->status !== 'archived' && $fallback->plan->status !== 'archived', 422);

        return ['trial_days' => $input['trialDays'] ?? null, 'grace_days' => $input['graceDays'] ?? null, 'trial_version_id' => $trial->id, 'fallback_version_id' => $fallback->id, 'eligibility_json' => $input['eligibility'] ?? [], 'security_termination_json' => $input['securityTermination'] ?? [], 'retention_json' => $input['retention'] ?? []];
    }

    public function policyResource(PlatformPolicyVersion $policy, array $versionUuids): array
    {
        return ['id' => $policy->uuid, 'number' => $policy->number, 'revision' => (string) $policy->revision, 'state' => $policy->state, 'contentHash' => $policy->content_hash, 'configuration' => ['trialDays' => $policy->trial_days, 'graceDays' => $policy->grace_days, 'trialVersionId' => $versionUuids[$policy->trial_version_id] ?? null, 'fallbackVersionId' => $versionUuids[$policy->fallback_version_id] ?? null, 'eligibility' => $policy->eligibility_json, 'securityTermination' => $policy->security_termination_json, 'retention' => $policy->retention_json]];
    }
}
