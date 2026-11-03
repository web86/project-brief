<?php

namespace App\Services\Plans;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EntitlementResolver
{
    public function __construct(private readonly EntitlementRegistry $registry, private readonly SubscriptionClock $clock) {}

    /** Metadata only, no authorization grant. Existing object/user permissions remain a separate check. */
    public function resolve(int $workspaceId, ?CarbonImmutable $at = null, bool $current = false): array
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc();
        $row = DB::table('workspace_subscriptions as s')->join('subscription_periods as p', function ($join): void {
            $join->on('p.id', '=', 's.current_period_id')->on('p.subscription_id', '=', 's.id')->on('p.workspace_id', '=', 's.workspace_id');
        })->join('plan_versions as v', 'v.id', '=', 'p.entitlement_version_id')->leftJoin('platform_policy_versions as policy', 'policy.id', '=', 'p.policy_version_id')->where('s.workspace_id', $workspaceId)->select(['s.id as subscription_id', 's.revision as subscription_revision', 's.approved_extra_seats', 's.enforcement_mode', 'p.*', 'v.state as version_state', 'v.access_class', 'v.developer_seats_json', 'v.registry_revision', 'v.content_hash', 'v.translations_json', 'v.price_json', 'policy.state as policy_state', 'policy.fallback_version_id as policy_fallback_version_id'])->when($current, fn ($q) => $q->sharedLock())->first();
        if (! $row || $row->version_state !== 'published') {
            return $this->unavailable('subscription_missing_or_incomplete');
        }
        try {
            $facts = ['kind' => $row->kind, 'starts_at' => CarbonImmutable::parse($row->starts_at, 'UTC'), 'service_ends_at' => $row->service_ends_at === null ? null : CarbonImmutable::parse($row->service_ends_at, 'UTC'), 'grace_ends_at' => $row->grace_ends_at === null ? null : CarbonImmutable::parse($row->grace_ends_at, 'UTC')];
            $phase = $this->clock->phase($facts, $at);
            if ($row->superseded_at !== null || ! in_array($phase['phase'], ['free', 'paid', 'trial', 'grace', 'compatibility'], true) || (($row->kind === 'free') !== ($row->access_class === 'free')) || (in_array($row->kind, ['paid', 'trial'], true) && ($row->policy_state !== 'published' || $row->fallback_version_id === null || $row->fallback_version_id !== $row->policy_fallback_version_id))) {
                return $this->unavailable('lifecycle_adapter_required');
            }
            if (DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')->exists() || DB::table('workspace_execution_epochs')->where('workspace_id', $workspaceId)->where('state', '<>', 'ready')->exists()) {
                return $this->unavailable('participation_adapter_required');
            }
            $values = [];
            foreach (DB::table('plan_entitlement_values')->where('plan_version_id', $row->entitlement_version_id)->get() as $value) {
                $decoded = json_decode($value->value_json, true, 16, JSON_THROW_ON_ERROR);
                $this->registry->validate($value->entitlement_key, $decoded, $value->scope_kind);
                if (! $this->registry->eligible($value->entitlement_key, $decoded, $row->access_class)) {
                    return $this->unavailable('ineligible_configuration');
                }
                $values[$value->entitlement_key] = $decoded;
            }
            $storedConfiguration = ['access_class' => $row->access_class, 'translations' => json_decode($row->translations_json, true, 16, JSON_THROW_ON_ERROR), 'price' => json_decode($row->price_json, true, 16, JSON_THROW_ON_ERROR), 'developer_seats' => json_decode($row->developer_seats_json, true, 16, JSON_THROW_ON_ERROR), 'entitlements' => $values];
            if (! is_string($row->content_hash) || ! hash_equals($row->content_hash, PlanConfiguration::versionHash($storedConfiguration))) {
                return $this->unavailable('version_integrity_invalid');
            }
            $availability = [];
            foreach ($this->registry->definitions() as $key => $definition) {
                $availability[$key] = $definition['availability'];
                if ($definition['availability'] !== 'available') {
                    unset($values[$key]);
                    if ($definition['default'] !== null) {
                        $values[$key] = $definition['default'];
                    }
                } elseif (! $definition['derived'] && ! isset($values[$key])) {
                    if ((int) $row->registry_revision >= EntitlementRegistry::REVISION) {
                        return $this->unavailable('required_value_missing');
                    }
                    $values[$key] = $definition['default'];
                }
            }
            $validUntil = $phase['valid_until'];
            $activeKeys = [];
            foreach (DB::table('workspace_entitlement_overrides')->where('workspace_id', $workspaceId)->where('subscription_id', $row->subscription_id)->when($current, fn ($q) => $q->sharedLock())->get() as $override) {
                $start = CarbonImmutable::parse($override->starts_at, 'UTC');
                $end = $override->ends_at === null ? null : CarbonImmutable::parse($override->ends_at, 'UTC');
                if ($start > $at) {
                    $validUntil = $validUntil === null || $start < $validUntil ? $start : $validUntil;

                    continue;
                }
                if ($end !== null && $end <= $at) {
                    continue;
                }
                $decoded = json_decode($override->value_json, true, 16, JSON_THROW_ON_ERROR);
                $this->registry->validate($override->entitlement_key, $decoded, $override->scope_kind);
                if (isset($activeKeys[$override->entitlement_key]) || $this->registry->definition($override->entitlement_key)['availability'] !== 'available' || ! $this->registry->eligible($override->entitlement_key, $decoded, $row->access_class)) {
                    return $this->unavailable('override_invalid');
                }
                $activeKeys[$override->entitlement_key] = true;
                $values[$override->entitlement_key] = $decoded;
                if ($end !== null && ($validUntil === null || $end < $validUntil)) {
                    $validUntil = $end;
                }
            }
            $values['developers.max'] = $this->registry->seats(json_decode($row->developer_seats_json, true, 16, JSON_THROW_ON_ERROR), (int) $row->approved_extra_seats, $row->access_class === 'compatibility');

            return ['state' => 'available', 'reason' => null, 'version_id' => (int) $row->entitlement_version_id, 'period_id' => (int) $row->id, 'policy_version_id' => $row->policy_version_id, 'subscription_revision' => (int) $row->subscription_revision, 'registry_revision' => EntitlementRegistry::REVISION, 'content_hash' => $row->content_hash, 'phase' => $phase['phase'], 'valid_until' => $validUntil?->format('Y-m-d\TH:i:s.u\Z'), 'availability' => $availability, 'values' => $values];
        } catch (InvalidArgumentException|\JsonException $e) {
            return $this->unavailable('configuration_invalid');
        }
    }

    private function unavailable(string $reason): array
    {
        return ['state' => 'unavailable', 'reason' => $reason, 'values' => [], 'valid_until' => null];
    }
}
