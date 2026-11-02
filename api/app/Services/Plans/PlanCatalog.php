<?php

namespace App\Services\Plans;

use App\Models\Plans\Plan;
use App\Models\Plans\PlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlanCatalog
{
    public function __construct(private readonly PlanCommands $commands, private readonly PlanConfiguration $validator, private readonly EntitlementRegistry $registry) {}

    public function create(User $actor, string $code, array $configuration, string $key): PlanVersion
    {
        $outcome = $this->commands->run($actor, 'plan.create', $key, compact('code', 'configuration'), function () use ($actor, $code, $configuration): array {
            if (! preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $code)) {
                throw new InvalidArgumentException('Invalid plan code.');
            }
            $configuration = $this->validator->validate($configuration);
            abort_if(Plan::where('code', $code)->exists(), 409, 'Plan code already exists.');
            $this->mirrorDefinitions();
            $plan = Plan::create(['code' => $code, 'status' => 'draft', 'visibility' => $configuration['visibility'], 'sort_order' => 0, 'revision' => 1, 'created_by' => $actor->id]);
            $version = $this->writeVersion($plan, $actor, $configuration, 1);

            return $this->outcome($version, 0);
        });

        return PlanVersion::findOrFail($outcome['id']);
    }

    public function revise(User $actor, int $versionId, int $revision, array $configuration, string $key): PlanVersion
    {
        $outcome = $this->commands->run($actor, 'plan.revise', $key, compact('versionId', 'revision', 'configuration'), function () use ($versionId, $revision, $configuration): array {
            $version = PlanVersion::whereKey($versionId)->lockForUpdate()->firstOrFail();
            abort_unless($version->state === 'draft' && $version->revision === $revision && $version->plan->status !== 'archived', 409, 'Draft revision conflict.');
            $configuration = $this->validator->validate($configuration);
            $version->update($this->attributes($configuration) + ['revision' => $revision + 1]);
            $version->plan->update(['visibility' => $configuration['visibility'], 'revision' => $version->plan->revision + 1]);
            $this->writeValues($version, $configuration['entitlements']);

            return $this->outcome($version, $revision);
        });

        return PlanVersion::findOrFail($outcome['id']);
    }

    public function publish(User $actor, int $versionId, int $revision, string $key): PlanVersion
    {
        $outcome = $this->commands->run($actor, 'plan.publish', $key, compact('versionId', 'revision'), function () use ($actor, $versionId, $revision): array {
            $version = PlanVersion::whereKey($versionId)->lockForUpdate()->firstOrFail();
            $plan = Plan::whereKey($version->plan_id)->lockForUpdate()->firstOrFail();
            abort_unless($version->state === 'draft' && $version->revision === $revision && $plan->status !== 'archived', 409, 'Draft revision conflict.');
            $configuration = $this->validator->validate($this->configuration($version));
            $version->update(['state' => 'published', 'content_hash' => PlanConfiguration::versionHash($configuration), 'revision' => $revision + 1, 'published_by' => $actor->id, 'published_at' => now('UTC')]);
            $plan->update(['published_version_id' => $version->id, 'status' => 'published', 'revision' => $plan->revision + 1]);

            return $this->outcome($version, $revision);
        });

        return PlanVersion::findOrFail($outcome['id']);
    }

    public function nextDraft(User $actor, int $planId, int $revision, string $key): PlanVersion
    {
        $outcome = $this->commands->run($actor, 'plan.next_draft', $key, compact('planId', 'revision'), function () use ($actor, $planId, $revision): array {
            $plan = Plan::whereKey($planId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $plan->revision === $revision && $plan->status === 'published' && ! $plan->versions()->where('state', 'draft')->exists(), 409, 'Plan revision conflict.');
            $configuration = $this->configuration(PlanVersion::findOrFail($plan->published_version_id));
            $version = $this->writeVersion($plan, $actor, $configuration, (int) $plan->versions()->max('number') + 1);
            $plan->increment('revision');

            return $this->outcome($version, 0);
        });

        return PlanVersion::findOrFail($outcome['id']);
    }

    public function archive(User $actor, int $planId, int $revision, string $key): Plan
    {
        $outcome = $this->commands->run($actor, 'plan.archive', $key, compact('planId', 'revision'), function () use ($planId, $revision): array {
            $plan = Plan::whereKey($planId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $plan->revision === $revision && $plan->status !== 'archived', 409);
            $plan->update(['status' => 'archived', 'revision' => $revision + 1]);

            return ['type' => 'plan', 'id' => $plan->id, 'uuid' => $plan->uuid, 'before' => $revision, 'after' => $revision + 1];
        });

        return Plan::findOrFail($outcome['id']);
    }

    public function transition(User $actor, int $source, int $target, array $rule, ?int $revision, string $key): int
    {
        $result = $this->commands->run($actor, 'plan.transition_rule', $key, compact('source', 'target', 'rule', 'revision'), function () use ($actor, $source, $target, $rule, $revision): array {
            $keys = array_keys($rule);
            sort($keys);
            $overage = $rule['overage_policy'] ?? [];
            $overageKeys = array_keys($overage);
            sort($overageKeys);
            if ($source === $target || $keys !== ['approval', 'enabled', 'initiator', 'overage_policy', 'timing'] || $overageKeys !== ['active', 'storage'] || ! in_array($rule['initiator'], ['platform', 'owner'], true) || ! in_array($rule['approval'], ['none', 'manual', 'payment'], true) || ! in_array($rule['timing'], ['immediate', 'period_end'], true) || ! is_bool($rule['enabled']) || ! in_array($overage['active'], ['select_and_suspend', 'reject'], true) || ! in_array($overage['storage'], ['read_only', 'reject'], true)) {
                throw new InvalidArgumentException('Invalid typed transition rule.');
            }
            $versions = PlanVersion::with('plan')->whereIn('id', [$source, $target])->get();
            abort_unless($versions->count() === 2 && $versions->every(fn ($v) => $v->state === 'published' && $v->plan->status !== 'archived'), 422);
            $targetVersion = $versions->firstWhere('id', $target);
            if ($targetVersion->access_class === 'compatibility' || ($targetVersion->access_class === 'paid' && $rule['approval'] === 'none')) {
                throw new InvalidArgumentException('Target requires platform approval.');
            }
            $existing = DB::table('plan_transition_rules')->where('source_version_id', $source)->where('target_version_id', $target)->where('initiator', $rule['initiator'])->lockForUpdate()->first();
            abort_unless($existing ? (int) $existing->revision === $revision : $revision === null, 409);
            $uuid = $existing?->uuid ?? (string) Str::uuid();
            $data = ['uuid' => $uuid, 'source_version_id' => $source, 'target_version_id' => $target, 'initiator' => $rule['initiator'], 'approval' => $rule['approval'], 'timing' => $rule['timing'], 'enabled' => $rule['enabled'], 'overage_policy' => json_encode($overage, JSON_THROW_ON_ERROR), 'revision' => ($revision ?? 0) + 1, 'updated_by' => $actor->id, 'updated_at' => now('UTC')];
            if ($existing) {
                DB::table('plan_transition_rules')->where('id', $existing->id)->update($data);
                $id = $existing->id;
            } else {
                $id = DB::table('plan_transition_rules')->insertGetId($data + ['created_by' => $actor->id, 'created_at' => now('UTC')]);
            }

            return ['type' => 'transition_rule', 'id' => (int) $id, 'uuid' => $uuid, 'before' => $revision ?? 0, 'after' => ($revision ?? 0) + 1];
        });

        return $result['id'];
    }

    public function configuration(PlanVersion $version): array
    {
        $values = DB::table('plan_entitlement_values')->where('plan_version_id', $version->id)->orderBy('entitlement_key')->get();
        $entitlements = [];
        foreach ($values as $row) {
            if ($row->scope_kind !== 'workspace') {
                throw new InvalidArgumentException('Unsupported stored scope.');
            }
            $entitlements[$row->entitlement_key] = json_decode($row->value_json, true, 16, JSON_THROW_ON_ERROR);
        }

        return ['access_class' => $version->access_class, 'visibility' => $version->access_class === 'compatibility' ? 'hidden' : $version->plan->visibility, 'translations' => $version->translations_json, 'price' => $version->price_json, 'developer_seats' => $version->developer_seats_json, 'entitlements' => $entitlements];
    }

    public function mirrorDefinitions(): void
    {
        foreach ($this->registry->definitions() as $key => $definition) {
            DB::table('entitlement_definitions')->updateOrInsert(['key' => $key], ['kind' => $definition['kind'], 'allowed_scopes_json' => json_encode($definition['scopes']), 'schema_json' => json_encode($definition), 'paid_requirement' => $definition['paid_requirement'], 'availability' => $definition['availability'], 'derived' => $definition['derived'], 'schema_revision' => EntitlementRegistry::REVISION]);
        }
    }

    private function writeVersion(Plan $plan, User $actor, array $configuration, int $number): PlanVersion
    {
        $version = PlanVersion::create($this->attributes($configuration) + ['plan_id' => $plan->id, 'number' => $number, 'state' => 'draft', 'revision' => 1, 'registry_revision' => EntitlementRegistry::REVISION, 'created_by' => $actor->id]);
        $this->writeValues($version, $configuration['entitlements']);

        return $version;
    }

    private function attributes(array $configuration): array
    {
        return ['access_class' => $configuration['access_class'], 'translations_json' => $configuration['translations'], 'price_json' => $configuration['price'], 'developer_seats_json' => $configuration['developer_seats']];
    }

    private function writeValues(PlanVersion $version, array $values): void
    {
        DB::table('plan_entitlement_values')->where('plan_version_id', $version->id)->delete();
        foreach ($values as $key => $value) {
            DB::table('plan_entitlement_values')->insert(['plan_version_id' => $version->id, 'entitlement_key' => $key, 'scope_kind' => 'workspace', 'value_json' => json_encode($value, JSON_THROW_ON_ERROR)]);
        }
    }

    private function outcome(PlanVersion $version, int $before): array
    {
        return ['type' => 'plan_version', 'id' => $version->id, 'uuid' => $version->uuid, 'before' => $before, 'after' => $version->revision];
    }
}
