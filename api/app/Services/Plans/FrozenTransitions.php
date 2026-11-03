<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\WorkspaceSubscription;
use App\Models\User;
use App\Services\IntegrationWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FrozenTransitions
{
    public function __construct(private readonly WorkspaceAllocation $allocation, private readonly ResourceSelections $selections) {}

    /** Domain authorization is repeated under workspace first; HTTP additionally supplies existing fresh MFA/CSRF gates. */
    public function authorize(User $actor, int $workspaceId, bool $platform): int
    {
        if ($platform) {
            return (int) app(PlanAuthority::class)->requirePrimary($actor)->revision;
        }
        abort_unless(DB::table('workspace_members')->where('workspace_id', $workspaceId)->where('user_id', $actor->id)->where('role', 'owner')->where('status', 'active')->lockForUpdate()->first() !== null, 403);

        return 0;
    }

    public function options(User $actor, int $workspaceId, bool $platform): array
    {
        return DB::transaction(function () use ($actor, $workspaceId, $platform): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $this->authorize($actor, $workspaceId, $platform);
            $snapshot = $this->allocation->capture($workspaceId);

            $subscription = $platform ? null : $this->subscriptionSummary($snapshot);

            return ['workspaceId' => $snapshot['workspaceId'], 'subscriptionRevision' => $snapshot['subscriptionRevision'], 'resources' => $this->selections->options($snapshot, ! $platform), 'usage' => $snapshot['usage'], 'resourceFingerprint' => $snapshot['hash'], 'previewOnly' => true, 'executorAvailable' => false, ...($platform ? [] : ['subscription' => $subscription])];
        }, 3);
    }

    private function subscriptionSummary(array $snapshot): ?array
    {
        $pinned = $snapshot['pinned'];
        if ($pinned['state'] !== 'available') {
            return null;
        }

        $period = $snapshot['rows']['subscription_periods']->firstWhere('id', $pinned['period_id']);
        $version = PlanVersion::findOrFail($pinned['version_id']);
        $end = $pinned['phase'] === 'grace' ? $period->grace_ends_at : $period->service_ends_at;

        return [
            'translations' => $version->translations_json,
            'phase' => $pinned['phase'],
            'startsAt' => CarbonImmutable::parse($period->starts_at, 'UTC')->toISOString(),
            'endsAt' => $end === null ? null : CarbonImmutable::parse($end, 'UTC')->toISOString(),
            'limits' => array_intersect_key($pinned['values'], array_flip(['projects.active.max', 'developers.max', 'clients.max', 'agents.active.max'])),
        ];
    }

    public function create(User $actor, int $workspaceId, bool $platform, string $version, array $selection, int $expectedRevision, string $key): array
    {
        return DB::transaction(function () use ($actor, $workspaceId, $platform, $version, $selection, $expectedRevision, $key): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $authorityRevision = $this->authorize($actor, $workspaceId, $platform);
            abort_unless(preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422);
            $operationHash = hash('sha256', $actor->id.'|resource-preview|'.$workspaceId.'|'.$key);
            $requestHash = PlanConfiguration::hash(compact('version', 'selection', 'expectedRevision', 'platform'));
            $previous = DB::table('plan_events')->where('operation_key_hash', $operationHash)->lockForUpdate()->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'preview_key_conflict');

                return $this->read($actor, $workspaceId, $platform, json_decode($previous->result_json, true)['previewId']);
            }
            $snapshot = $this->allocation->capture($workspaceId);
            abort_unless((int) $snapshot['subscriptionRevision'] === $expectedRevision, 409, 'subscription_revision_conflict');
            $target = $this->selections->target($version, ! $platform);
            $plan = $this->selections->plan($snapshot, $target, $selection);
            if ($snapshot['pinnedState'] !== 'available') {
                $plan['state'] = 'needs_review';
                $plan['reasonCodes'][] = 'subscription_missing_or_incomplete';
            }
            $uuid = (string) Str::uuid();
            $now = CarbonImmutable::now('UTC');
            $settings = DB::table('plan_settings')->where('id', 1)->sharedLock()->value('revision') ?? 0;
            $summary = $plan + ['target' => $target, 'resourceFingerprint' => $snapshot['hash'], 'resourceRevisions' => $snapshot['fingerprints'], 'executionContract' => $this->selections->executionContract($snapshot, $target, $plan)];
            $id = DB::table('plan_previews')->insertGetId(['uuid' => $uuid, 'actor_user_id' => $actor->id, 'authority_revision' => $authorityRevision, 'kind' => 'resource_transition', 'target_uuid' => $target['id'], 'target_revision' => $target['revision'], 'target_hash' => $target['hash'], 'settings_revision' => $settings, 'registry_revision' => EntitlementRegistry::REVISION, 'policy_json' => json_encode(['selection' => $plan['selection'], 'platform' => $platform]), 'state' => 'ready', 'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR), 'ready_at' => $now->format('Y-m-d H:i:s.u'), 'expires_at' => $now->addMinutes(15)->format('Y-m-d H:i:s.u'), 'created_at' => $now->format('Y-m-d H:i:s.u'), 'updated_at' => $now->format('Y-m-d H:i:s.u')]);
            DB::table('plan_preview_items')->insert(['preview_id' => $id, 'workspace_id' => $workspaceId, 'subscription_revision' => $expectedRevision, 'usage_fingerprint' => $snapshot['hash'], 'execution_epochs_json' => json_encode($snapshot['fingerprints']['workspace_execution_epochs']), 'suspension_revision' => $snapshot['rows']['workspace_suspension_cycles']->max('revision') ?? 0, 'evaluated_at' => $now->format('Y-m-d H:i:s.u'), 'result_json' => json_encode(['selectionHash' => $plan['selectionHash']]), 'apply_state' => 'pending']);
            $this->audit($actor, $workspaceId, $platform, 'transition.preview_created', ['previewId' => $uuid, 'selectionHash' => $plan['selectionHash'], 'state' => $plan['state']], $operationHash, $requestHash);

            return $this->resource(DB::table('plan_previews')->where('id', $id)->first());
        }, 3);
    }

    /** Reopening and verification recompute current reads. No apply endpoint or execution receipt exists. */
    public function read(User $actor, int $workspaceId, bool $platform, string $uuid): array
    {
        return DB::transaction(function () use ($actor, $workspaceId, $platform, $uuid): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $authorityRevision = $this->authorize($actor, $workspaceId, $platform);
            $row = DB::table('plan_previews')->join('plan_preview_items as item', 'item.preview_id', '=', 'plan_previews.id')->where('plan_previews.uuid', $uuid)->where('kind', 'resource_transition')->where('actor_user_id', $actor->id)->where('item.workspace_id', $workspaceId)->select('plan_previews.*')->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if(CarbonImmutable::parse($row->expires_at, 'UTC') <= CarbonImmutable::now('UTC'), 409, 'transition_preview_expired');
            abort_unless((int) $row->authority_revision === $authorityRevision, 409, 'transition_authority_changed');
            $stored = json_decode($row->summary_json, true, 32, JSON_THROW_ON_ERROR);
            $policy = json_decode($row->policy_json, true, 32, JSON_THROW_ON_ERROR);
            abort_unless($policy['platform'] === $platform, 404);
            $snapshot = $this->allocation->capture($workspaceId);
            try {
                $target = $this->selections->target($row->target_uuid, ! $platform);
            } catch (ModelNotFoundException|HttpException $e) {
                abort(409, 'transition_preview_stale');
            }
            abort_unless(hash_equals($stored['resourceFingerprint'], $snapshot['hash']) && PlanConfiguration::hash($stored['target']) === PlanConfiguration::hash($target) && (int) $row->settings_revision === (int) (DB::table('plan_settings')->where('id', 1)->sharedLock()->value('revision') ?? 0) && (int) $row->registry_revision === EntitlementRegistry::REVISION, 409, 'transition_preview_stale');
            $current = $this->selections->plan($snapshot, $target, $policy['selection']);
            abort_unless($current['selectionHash'] === $stored['selectionHash'], 409, 'transition_selection_changed');

            return $this->resource($row) + ['verified' => true, 'mutationSupported' => false];
        }, 3);
    }

    /** Apply reviewed participation to the pinned period. Premium execution remains blocked. */
    public function applySelection(User $actor, int $workspaceId, string $previewId, int $expectedRevision, string $key, bool $restorePaid = false): array
    {
        return DB::transaction(function () use ($actor, $workspaceId, $previewId, $expectedRevision, $key, $restorePaid): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $this->authorize($actor, $workspaceId, false);
            abort_unless(preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422);
            $operation = $restorePaid ? 'paid_restore' : 'basic_selection';
            $operationHash = hash('sha256', $actor->id.'|'.$operation.'|'.$workspaceId.'|'.$key);
            $requestHash = PlanConfiguration::hash(compact('previewId', 'expectedRevision'));
            $previous = DB::table('plan_events')->where('operation_key_hash', $operationHash)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'preview_key_conflict');

                return json_decode($previous->result_json, true, 16, JSON_THROW_ON_ERROR);
            }
            $subscription = WorkspaceSubscription::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->revision === $expectedRevision, 409, 'subscription_revision_conflict');
            $period = $subscription->currentPeriod()->firstOrFail();
            $cycles = DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')->limit(2)->lockForUpdate()->get();
            $cycle = $cycles->first();
            $eligible = $restorePaid
                ? $period->kind === 'paid' && $period->approval_kind === 'manual' && $period->approval_change_id !== null
                    && $period->service_ends_at > CarbonImmutable::now('UTC')
                : $period->kind === 'free' && $period->approval_kind === 'system'
                    && (int) $cycle?->target_version_id === (int) $period->entitlement_version_id;
            abort_unless($eligible && $cycles->count() === 1 && $cycle->reason_code === 'subscription_expired', 409, 'selection_apply_unavailable');
            $preview = $this->read($actor, $workspaceId, false, $previewId);
            abort_unless($preview['target']['id'] === $period->version()->value('uuid')
                && (int) $preview['subscriptionRevision'] === $expectedRevision, 409, 'transition_preview_stale');

            $selection = $preview['selection'];
            abort_if(array_diff($preview['reasonCodes'], ['subscription_missing_or_incomplete', 'participation_cycle_adapter_required'])
                || $selection['agents'] || $selection['invitations'], 422, 'selection_apply_invalid');
            abort_if(! $restorePaid && (count($selection['projects']) !== 1 || count($selection['developers']) !== 1
                || count($selection['clients']) > 3), 422, 'basic_selection_invalid');

            $projects = DB::table('projects')->where('workspace_id', $workspaceId)->whereIn('uuid', $selection['projects'])
                ->whereNull('deleted_at')->where('status', 'active')->orderBy('id')->get(['id', 'uuid']);
            $clients = DB::table('project_clients')->whereIn('project_id', $projects->pluck('id'))
                ->where('active', true)->whereIn('uuid', $preview['clientParticipations'])->orderBy('id')->get(['id', 'uuid']);
            $members = DB::table('workspace_members')->where('workspace_id', $workspaceId)->where('status', 'active')->where('role', 'admin')
                ->whereIn('id', $selection['developers'])->orderBy('id')->get(['id']);
            abort_unless($projects->count() === count($selection['projects'])
                && $clients->count() === count($preview['clientParticipations'])
                && $members->count() + 1 === count($selection['developers']), 409, 'transition_preview_stale');

            $at = CarbonImmutable::now('UTC');
            abort_if($restorePaid && $period->service_ends_at <= $at, 409, 'selection_apply_unavailable');
            $now = $at->format('Y-m-d H:i:s.u');
            $scope = ['workspace_id' => $workspaceId, 'cycle_id' => $cycle->id];
            DB::table('workspace_entitlement_resource_states')->where($scope)
                ->whereIn('resource_kind', ['project', 'project_client', 'workspace_member'])->where('state', 'released')
                ->update(['state' => 'held', 'reviewed_revision' => $expectedRevision, 'confirmed_by' => $actor->id, 'reason_code' => 'selection_replaced', 'changed_at' => $now]);
            foreach (['project' => $projects, 'project_client' => $clients, 'workspace_member' => $members] as $kind => $resources) {
                foreach ($resources as $resource) {
                    DB::table('workspace_entitlement_resource_states')->updateOrInsert($scope + ['resource_kind' => $kind, 'resource_id' => $resource->id], [
                        'resource_uuid' => $resource->uuid ?? null,
                        'state' => 'released',
                        'reviewed_revision' => $expectedRevision,
                        'confirmed_by' => $actor->id,
                        'reason_code' => $operation,
                        'changed_at' => $now,
                    ]);
                }
            }
            DB::table('workspace_suspension_cycles')->where('id', $cycle->id)->increment('revision');
            $subscription->increment('revision');
            $result = ['previewId' => $previewId, 'selectionHash' => $preview['selectionHash'], 'cycleId' => $cycle->uuid, 'subscriptionRevision' => (string) $subscription->revision];
            $this->audit($actor, $workspaceId, false, $restorePaid ? 'transition.paid_restored' : 'transition.basic_selected', $result, $operationHash, $requestHash);

            return $result;
        }, 3);
    }

    public function audit(User $actor, int $workspaceId, bool $platform, string $action, array $metadata, ?string $keyHash = null, ?string $requestHash = null): void
    {
        DB::table('plan_events')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'aggregate_type' => 'resource_preparation', 'aggregate_uuid' => DB::table('workspaces')->where('id', $workspaceId)->value('uuid'), 'actor_kind' => $platform ? 'primary' : 'owner', 'actor_user_id' => $actor->id, 'action' => $action, 'result' => 'applied', 'diff_json' => json_encode($metadata, JSON_THROW_ON_ERROR), 'operation_key_hash' => $keyHash, 'request_hash' => $requestHash, 'result_json' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }

    private function resource(object $row): array
    {
        return ['id' => $row->uuid, 'expiresAt' => CarbonImmutable::parse($row->expires_at, 'UTC')->format('Y-m-d\TH:i:s.u\Z'), 'subscriptionRevision' => (string) DB::table('plan_preview_items')->where('preview_id', $row->id)->value('subscription_revision'), ...json_decode($row->summary_json, true, 32, JSON_THROW_ON_ERROR)];
    }
}
