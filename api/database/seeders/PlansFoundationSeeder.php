<?php

namespace Database\Seeders;

use App\Models\Plans\Plan;
use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Services\Plans\EntitlementRegistry;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlanConfiguration;
use App\Services\Plans\PlatformPolicyCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlansFoundationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('plan_settings')->insertOrIgnore(['id' => 1, 'registry_revision' => EntitlementRegistry::REVISION, 'rollout_mode' => 'off', 'revision' => 1]);
            DB::table('plan_settings')->where('id', 1)->lockForUpdate()->first();
            $operationHash = hash('sha256', 'plans.foundation.seed.v1');
            if (DB::table('plan_events')->where('operation_key_hash', $operationHash)->exists()) {
                return;
            }
            app(PlanCatalog::class)->mirrorDefinitions();
            $versions = [];
            foreach (['basic', 'freelancer', 'studio'] as $code) {
                $plan = Plan::firstOrCreate(['code' => $code], ['status' => 'draft', 'visibility' => 'public', 'revision' => 1, 'sort_order' => count($versions)]);
                $version = $plan->versions()->orderBy('number')->first();
                if ($version === null) {
                    $config = app(PlanConfiguration::class)->validate(self::configuration($code));
                    $version = PlanVersion::create(['plan_id' => $plan->id, 'number' => 1, 'state' => 'draft', 'access_class' => $config['access_class'], 'translations_json' => $config['translations'], 'price_json' => $config['price'], 'developer_seats_json' => $config['developer_seats'], 'registry_revision' => EntitlementRegistry::REVISION, 'revision' => 1]);
                    foreach ($config['entitlements'] as $key => $value) {
                        DB::table('plan_entitlement_values')->insert(['plan_version_id' => $version->id, 'entitlement_key' => $key, 'scope_kind' => 'workspace', 'value_json' => json_encode($value, JSON_THROW_ON_ERROR)]);
                    }
                }
                $versions[$code] = $version;
            }
            if (! PlatformPolicyVersion::exists()) {
                PlatformPolicyVersion::create(['number' => 1, 'state' => 'draft', 'trial_days' => 7, 'grace_days' => 7, 'trial_version_id' => $versions['studio']->id, 'fallback_version_id' => $versions['basic']->id, 'eligibility_json' => PlatformPolicyCatalog::eligibility(), 'security_termination_json' => ['mode' => 'manual_review'], 'retention_json' => PlatformPolicyCatalog::retention(), 'revision' => 1]);
            }
            DB::table('plan_events')->insert(['uuid' => (string) Str::uuid(), 'aggregate_type' => 'initialization', 'aggregate_uuid' => (string) Str::uuid(), 'actor_kind' => 'operator', 'action' => 'plans.seed_drafts', 'result' => 'applied', 'diff_json' => json_encode(['registry_revision' => EntitlementRegistry::REVISION]), 'operation_key_hash' => $operationHash, 'created_at' => now('UTC')]);
        }, 3);
    }

    public static function configuration(string $code): array
    {
        $index = array_search($code, ['basic', 'freelancer', 'studio'], true);
        if ($index === false) {
            throw new \InvalidArgumentException('Unknown initial seed identifier.');
        }
        $integer = fn (int $value): array => ['kind' => 'integer', 'value' => $value];
        $boolean = fn (bool $value): array => ['kind' => 'boolean', 'value' => $value];
        $unlimited = ['kind' => 'unlimited'];
        $values = ['projects.active.max' => $integer([1, 10, 100][$index]), 'clients.max' => $index === 0 ? $integer(3) : $unlimited, 'agents.active.max' => [$integer(0), $integer(1), $unlimited][$index], 'agents.enabled' => $boolean($index > 0), 'api.agents.enabled' => $boolean($index > 0), 'api.external.enabled' => $boolean($index === 2), 'webhooks.enabled' => $boolean($index === 2), 'sso.enabled' => $boolean($index === 2), 'branding.level' => ['kind' => 'enum', 'value' => ['none', 'basic', 'full'][$index]]];
        ksort($values);

        return ['visibility' => 'public', 'access_class' => $index === 0 ? 'free' : 'paid', 'translations' => array_fill_keys(['ru', 'en', 'tr', 'zh-CN'], ['name' => ucfirst($code), 'description' => '']), 'price' => ['mode' => $index === 0 ? 'free' : 'paid', 'amount_minor' => null, 'currency' => null, 'scale' => 2, 'interval' => null], 'developer_seats' => ['included' => $index === 2 ? 10 : 1, 'hard_max' => $index === 2 ? 50 : 1, 'allow_extra' => $index === 2], 'entitlements' => $values];
    }
}
