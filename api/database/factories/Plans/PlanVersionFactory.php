<?php

namespace Database\Factories\Plans;

use App\Models\Plans\Plan;
use App\Models\Plans\PlanVersion;
use App\Services\Plans\EntitlementRegistry;
use App\Services\Plans\PlanCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<PlanVersion> */
class PlanVersionFactory extends Factory
{
    protected $model = PlanVersion::class;

    public function definition(): array
    {
        return ['plan_id' => Plan::factory(), 'number' => 1, 'state' => 'draft', 'access_class' => 'free', 'translations_json' => array_fill_keys(['ru', 'en', 'tr', 'zh-CN'], ['name' => 'Synthetic plan', 'description' => '']), 'price_json' => ['mode' => 'free', 'amount_minor' => null, 'currency' => null, 'scale' => 2, 'interval' => null], 'developer_seats_json' => ['included' => 1, 'hard_max' => 1, 'allow_extra' => false], 'registry_revision' => EntitlementRegistry::REVISION, 'revision' => 1];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (PlanVersion $version): void {
            app(PlanCatalog::class)->mirrorDefinitions();
            foreach (app(EntitlementRegistry::class)->definitions() as $key => $definition) {
                if ($definition['availability'] === 'available' && ! $definition['derived']) {
                    DB::table('plan_entitlement_values')->insert(['plan_version_id' => $version->id, 'entitlement_key' => $key, 'scope_kind' => 'workspace', 'value_json' => json_encode($definition['default'], JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }
}
