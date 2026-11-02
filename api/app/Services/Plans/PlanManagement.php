<?php

namespace App\Services\Plans;

use App\Models\Plans\Plan;
use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlanManagement
{
    public function __construct(private readonly PlanCommands $commands, private readonly PlanCatalog $catalog, private readonly PlatformPolicyCatalog $policies) {}

    public function preview(User $actor, PlanVersion|PlatformPolicyVersion $target, int $revision, string $key): object
    {
        $result = $this->commands->run($actor, 'management.preview', $key, ['target' => $target->uuid, 'revision' => $revision, 'policy' => 'keep_existing'], function (object $authority) use ($actor, $target, $revision): array {
            $target->refresh();
            abort_unless($target->state === 'draft' && $target->revision === $revision, 409);
            $isPlan = $target instanceof PlanVersion;
            $config = $isPlan ? $this->catalog->configuration($target) : $this->policies->configuration($target);
            if ($isPlan) {
                app(PlanConfiguration::class)->validate($config);
                abort_if($target->plan->status === 'archived', 409);
                $previous = $target->plan->published_version_id ? PlanVersion::findOrFail($target->plan->published_version_id) : null;
                $before = $previous ? $this->catalog->configuration($previous) : [];
            } else {
                $uuids = PlanVersion::whereIn('id', [$target->trial_version_id, $target->fallback_version_id])->pluck('uuid', 'id')->all();
                app(PlanInput::class)->policy(app(PlanInput::class)->policyResource($target, $uuids)['configuration']);
                $previous = PlatformPolicyVersion::where('state', 'published')->latest('number')->first();
                $before = $previous ? $this->policies->configuration($previous) : [];
            }
            $hash = $isPlan ? PlanConfiguration::versionHash($config) : PlanConfiguration::hash($config);
            $summary = ['policy' => 'keep_existing', 'impact' => ['state' => 'unavailable', 'reason' => 'accounting_providers_not_connected'], 'diff' => $this->diff($before, $config), 'catalogRevision' => $isPlan ? (int) $target->plan->revision : null, 'baseVersionId' => $previous?->uuid];
            $id = $this->storePreview($actor, $authority, $isPlan ? 'plan_publish' : 'policy_publish', $target->uuid, $revision, $hash, $summary);

            return ['type' => 'plan_preview', 'id' => $id, 'uuid' => DB::table('plan_previews')->where('id', $id)->value('uuid'), 'before' => 0, 'after' => 1];
        });

        return DB::table('plan_previews')->where('id', $result['id'])->first();
    }

    public function publish(User $actor, PlanVersion|PlatformPolicyVersion $target, int $revision, string $previewUuid, string $hash, string $key): PlanVersion|PlatformPolicyVersion
    {
        $isPlan = $target instanceof PlanVersion;
        $result = $this->commands->run($actor, 'management.publish', $key, ['target' => $target->uuid, 'revision' => $revision, 'preview' => $previewUuid, 'hash' => $hash, 'policy' => 'keep_existing'], function (object $authority) use ($actor, $target, $revision, $previewUuid, $hash, $key, $isPlan): array {
            $target->refresh();
            $preview = $this->validPreview($actor, $authority, $previewUuid, $isPlan ? 'plan_publish' : 'policy_publish');
            $config = $isPlan ? $this->catalog->configuration($target) : $this->policies->configuration($target);
            $currentHash = $isPlan ? PlanConfiguration::versionHash($config) : PlanConfiguration::hash($config);
            $summary = json_decode($preview->summary_json, true, 16, JSON_THROW_ON_ERROR);
            abort_unless($target->uuid === $preview->target_uuid && $revision === (int) $preview->target_revision && $target->revision === $revision && hash_equals($currentHash, $hash) && hash_equals($preview->target_hash, $hash), 409);
            if ($isPlan) {
                abort_unless((int) $target->plan->fresh()->revision === $summary['catalogRevision'], 409);
                $published = $this->catalog->publish($actor, $target->id, $revision, 'confirmed:'.hash('sha256', $key));
            } else {
                $published = $this->policies->publish($actor, $target->id, $revision, 'confirmed:'.hash('sha256', $key));
                DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $published->id, 'revision' => DB::raw('revision + 1')]);
            }

            return ['type' => $isPlan ? 'plan_version' : 'platform_policy', 'id' => $published->id, 'uuid' => $published->uuid, 'before' => $revision, 'after' => $published->revision];
        });

        return $isPlan ? PlanVersion::findOrFail($result['id']) : PlatformPolicyVersion::findOrFail($result['id']);
    }

    public function metadata(User $actor, Plan $plan, int $revision, array $input, string $key): Plan
    {
        $this->commands->run($actor, 'plan.metadata', $key, ['plan' => $plan->uuid, 'revision' => $revision, 'input' => $input], function () use ($plan, $revision, $input): array {
            $plan->refresh();
            abort_unless((int) $plan->revision === $revision, 409);
            PlanInput::only($input, ['visibility', 'sortOrder']);
            abort_unless(in_array($input['visibility'] ?? null, ['public', 'hidden'], true) && is_int($input['sortOrder'] ?? null) && abs($input['sortOrder']) <= 100000, 422);
            abort_if($input['visibility'] !== 'hidden' && $plan->versions()->where('access_class', 'compatibility')->exists(), 422);
            $plan->update(['visibility' => $input['visibility'], 'sort_order' => $input['sortOrder'], 'revision' => $revision + 1]);

            return ['type' => 'plan', 'id' => $plan->id, 'uuid' => $plan->uuid, 'before' => $revision, 'after' => $revision + 1];
        });

        return $plan->fresh();
    }

    public function unarchive(User $actor, Plan $plan, int $revision, string $key): Plan
    {
        $this->commands->run($actor, 'plan.unarchive', $key, ['plan' => $plan->uuid, 'revision' => $revision], function () use ($plan, $revision): array {
            $plan->refresh();
            abort_unless($plan->status === 'archived' && (int) $plan->revision === $revision, 409);
            $plan->update(['status' => $plan->published_version_id ? 'published' : 'draft', 'revision' => $revision + 1]);

            return ['type' => 'plan', 'id' => $plan->id, 'uuid' => $plan->uuid, 'before' => $revision, 'after' => $revision + 1];
        });

        return $plan->fresh();
    }

    public function copyPreview(User $actor, PlanVersion $from, PlanVersion $to, string $key): object
    {
        $result = $this->commands->run($actor, 'rules.copy_preview', $key, ['from' => $from->uuid, 'to' => $to->uuid], function (object $authority) use ($actor, $from, $to): array {
            $summary = $this->copyCandidates($from, $to);
            $id = $this->storePreview($actor, $authority, 'rules_copy', $to->uuid, $to->revision, PlanConfiguration::hash($summary), $summary);

            return ['type' => 'plan_preview', 'id' => $id, 'uuid' => DB::table('plan_previews')->where('id', $id)->value('uuid'), 'before' => 0, 'after' => 1];
        });

        return DB::table('plan_previews')->where('id', $result['id'])->first();
    }

    public function copyRules(User $actor, string $previewUuid, string $hash, string $key): object
    {
        $result = $this->commands->run($actor, 'rules.copy', $key, compact('previewUuid', 'hash'), function (object $authority) use ($actor, $previewUuid, $hash, $key): array {
            $preview = $this->validPreview($actor, $authority, $previewUuid, 'rules_copy');
            $summary = json_decode($preview->summary_json, true, 16, JSON_THROW_ON_ERROR);
            $from = PlanVersion::where('uuid', $summary['from'])->firstOrFail();
            $to = PlanVersion::where('uuid', $summary['to'])->firstOrFail();
            $current = $this->copyCandidates($from, $to);
            abort_unless(hash_equals($preview->target_hash, $hash) && hash_equals($hash, PlanConfiguration::hash($current)) && $current['conflicts'] === [], 409);
            foreach ($current['candidates'] as $index => $candidate) {
                $source = PlanVersion::where('uuid', $candidate['source'])->firstOrFail();
                $target = PlanVersion::where('uuid', $candidate['target'])->firstOrFail();
                $this->catalog->transition($actor, $source->id, $target->id, $candidate['rule'], null, 'copy:'.hash('sha256', $key.'|'.$index));
            }

            return ['type' => 'plan_preview', 'id' => $preview->id, 'uuid' => $preview->uuid, 'before' => 0, 'after' => 1];
        });

        return DB::table('plan_previews')->where('id', $result['id'])->first();
    }

    private function copyCandidates(PlanVersion $from, PlanVersion $to): array
    {
        abort_unless($from->id !== $to->id && $from->plan_id === $to->plan_id && $from->state === 'published' && $to->state === 'published' && $to->plan->status !== 'archived', 422);
        $rules = DB::table('plan_transition_rules')->where(fn ($q) => $q->whereIn('source_version_id', [$from->id, $to->id])->orWhereIn('target_version_id', [$from->id, $to->id]))->orderBy('id')->limit(101)->get();
        abort_if($rules->count() > 100, 503);
        $versions = PlanVersion::with('plan')->whereIn('id', $rules->pluck('source_version_id')->merge($rules->pluck('target_version_id'))->push($to->id))->get()->keyBy('id');
        $candidates = $conflicts = [];
        foreach ($rules as $rule) {
            if ($rule->source_version_id !== $from->id && $rule->target_version_id !== $from->id) {
                continue;
            }
            $source = $rule->source_version_id === $from->id ? $to->id : $rule->source_version_id;
            $target = $rule->target_version_id === $from->id ? $to->id : $rule->target_version_id;
            if ($source === $target || $rules->contains(fn ($r) => $r->source_version_id === $source && $r->target_version_id === $target && $r->initiator === $rule->initiator) || $versions[$source]->plan->status === 'archived' || $versions[$target]->plan->status === 'archived') {
                $conflicts[] = $rule->uuid;

                continue;
            }
            $candidates[] = ['source' => $versions[$source]->uuid, 'target' => $versions[$target]->uuid, 'rule' => ['initiator' => $rule->initiator, 'approval' => 'manual', 'timing' => $rule->timing, 'overage_policy' => json_decode($rule->overage_policy, true), 'enabled' => false]];
        }

        return ['from' => $from->uuid, 'to' => $to->uuid, 'candidates' => $candidates, 'conflicts' => $conflicts, 'rulesFingerprint' => PlanConfiguration::hash($rules->map(fn ($r) => (array) $r)->all())];
    }

    private function storePreview(User $actor, object $authority, string $kind, string $uuid, int $revision, string $hash, array $summary): int
    {
        DB::table('plan_settings')->insertOrIgnore(['id' => 1, 'rollout_mode' => 'off', 'registry_revision' => EntitlementRegistry::REVISION, 'revision' => 1]);
        $settings = DB::table('plan_settings')->where('id', 1)->lockForUpdate()->first();

        return DB::table('plan_previews')->insertGetId(['uuid' => (string) Str::uuid(), 'actor_user_id' => $actor->id, 'authority_revision' => $authority->revision, 'kind' => $kind, 'target_uuid' => $uuid, 'target_revision' => $revision, 'target_hash' => $hash, 'settings_revision' => $settings->revision, 'registry_revision' => EntitlementRegistry::REVISION, 'policy_json' => json_encode(['mode' => 'keep_existing']), 'state' => 'ready', 'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR), 'ready_at' => now('UTC'), 'expires_at' => now('UTC')->addMinutes(15), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    private function validPreview(User $actor, object $authority, string $uuid, string $kind): object
    {
        $preview = DB::table('plan_previews')->where('uuid', $uuid)->where('actor_user_id', $actor->id)->where('kind', $kind)->lockForUpdate()->first();
        abort_unless($preview, 404);
        $settings = DB::table('plan_settings')->where('id', 1)->lockForUpdate()->first();
        abort_unless($preview->state === 'ready' && $preview->expires_at > now('UTC')->format('Y-m-d H:i:s.u') && (int) $preview->authority_revision === (int) $authority->revision && (int) $preview->registry_revision === EntitlementRegistry::REVISION && (int) $preview->settings_revision === (int) $settings->revision, 409);

        return $preview;
    }

    private function diff(array $before, array $after, string $prefix = ''): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (is_array($old) || is_array($new)) {
                $diff = [...$diff, ...$this->diff(is_array($old) ? $old : [], is_array($new) ? $new : [], $prefix.$key.'.')];
            } elseif ($old !== $new) {
                $diff[] = ['field' => $prefix.$key, 'before' => $old, 'after' => $new];
            }
        }

        return $diff;
    }
}
