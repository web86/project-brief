<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\SubscriptionPeriod;
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

    public function options(User $actor, int $workspaceId, bool $platform, bool $manual = false): array
    {
        return DB::transaction(function () use ($actor, $workspaceId, $platform, $manual): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $this->authorize($actor, $workspaceId, $platform);
            $snapshot = $this->allocation->capture($workspaceId);

            $subscription = $platform && ! $manual ? null : $this->subscriptionSummary($snapshot);

            return ['workspaceId' => $snapshot['workspaceId'], 'subscriptionRevision' => $snapshot['subscriptionRevision'], 'resources' => $this->selections->options($snapshot, ! $platform || $manual, $manual), 'usage' => $snapshot['usage'], 'resourceFingerprint' => $snapshot['hash'], 'previewOnly' => true, 'executorAvailable' => false, ...($platform && ! $manual ? [] : ['subscription' => $subscription, 'rearmGroups' => $platform ? [] : $this->rearmGroups($snapshot)]),
                ...($manual ? [
                    'assignableVersions' => ($subscription && $subscription['phase'] !== 'compatibility' && $snapshot['rows']['workspace_subscriptions']->first()?->enforcement_mode === 'enforce')
                        ? app(SubscriptionAssignments::class)->manualVersions(includeLimits: true) : [],
                    'replacementRequired' => in_array($subscription['phase'] ?? null, ['trial', 'paid', 'grace'], true),
                ] : []), ];
        }, 3);
    }

    private function rearmGroups(array $snapshot): array
    {
        $cycle = $snapshot['rows']['workspace_suspension_cycles']->firstWhere('state', 'open');
        if (! $cycle || ($snapshot['pinned']['phase'] ?? null) !== 'paid') {
            return [];
        }
        $restore = DB::table('plan_events')->where('workspace_id', $snapshot['workspaceInternalId'])
            ->where('action', 'transition.paid_restored')->latest('id')->value('result_json');
        $result = $restore ? json_decode($restore, true, 16, JSON_THROW_ON_ERROR) : [];
        if (($result['cycleId'] ?? null) !== $cycle->uuid || ($result['subscriptionRevision'] ?? null) !== $snapshot['subscriptionRevision']) {
            return [];
        }

        return array_keys(array_filter(ExecutionBarrier::FEATURES, fn (array $features): bool => collect($features)
            ->every(fn (string $key): bool => ($snapshot['pinned']['values'][$key]['value'] ?? null) === true)));
    }

    private function availableAction(array $snapshot, array $target, array $plan, bool $platform): ?string
    {
        if (array_diff($plan['reasonCodes'], ['participation_cycle_adapter_required', 'subscription_missing_or_incomplete'])) {
            return null;
        }
        $pinned = $snapshot['pinned'];
        $phase = $pinned['phase'] ?? null;
        $cycle = $snapshot['rows']['workspace_suspension_cycles']->firstWhere('state', 'open');
        if ($platform) {
            if ($target['accessClass'] !== 'paid' || $snapshot['rows']['workspace_subscriptions']->first()?->enforcement_mode !== 'enforce') {
                return null;
            }

            return ($cycle && $phase === 'free') || (in_array($phase, ['paid', 'grace'], true) && ! array_filter($plan['wouldHold'])
                && hash_equals($pinned['content_hash'], $target['hash'])) ? 'approve-paid' : null;
        }
        if (! $cycle || ! $phase || ! hash_equals($pinned['content_hash'], $target['hash'])) {
            return null;
        }

        $selection = $plan['selection'];

        return match (true) {
            $phase === 'paid' => 'restore-paid',
            $phase === 'free' && count($selection['projects']) === 1 && count($selection['developers']) === 1
                && count($selection['clients']) <= 3 && ! $selection['agents'] && ! $selection['invitations'] => 'apply-basic',
            default => null,
        };
    }

    public function describePeriod(?SubscriptionPeriod $period): ?array
    {
        if (! $period?->version) {
            return null;
        }
        $clock = app(SubscriptionClock::class)->phase($period->only(['kind', 'starts_at', 'service_ends_at', 'grace_ends_at']), CarbonImmutable::now('UTC'));
        $end = $clock['phase'] === 'grace' ? $period->grace_ends_at : $period->service_ends_at;

        return [
            'translations' => $period->version->translations_json,
            'phase' => $clock['phase'],
            'assignment' => $period->approval_kind,
            'startsAt' => $period->starts_at->toISOString(),
            'endsAt' => $end?->toISOString(),
        ];
    }

    public function summary(int $workspaceId): array
    {
        return DB::transaction(function () use ($workspaceId): array {
            DB::table('workspaces')->where('id', $workspaceId)->sharedLock()->firstOrFail();
            $subscription = WorkspaceSubscription::with('currentPeriod.version')->where('workspace_id', $workspaceId)->first();
            $period = $subscription?->currentPeriod;
            $summary = $this->describePeriod($period);
            $resolved = app(EntitlementResolver::class)->resolve($workspaceId, inspectSuspended: true);
            $limits = array_intersect_key($resolved['values'], array_flip(['projects.active.max', 'developers.max', 'clients.max', 'agents.active.max']));
            $openCycle = DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')->exists();
            $selectionTarget = $period && ($openCycle || in_array($period->kind, ['trial', 'paid'], true))
                ? PlanVersion::whereKey($openCycle ? $period->entitlement_version_id : $period->fallback_version_id)
                    ->where('state', 'published')->whereHas('plan', fn ($q) => $q->where('status', '<>', 'archived')->where('visibility', 'public'))->value('uuid') : null;

            if ($period?->kind === 'compatibility') {
                $selectionTarget = PlanVersion::where('state', 'published')->where('access_class', 'free')
                    ->whereHas('plan', fn ($plan) => $plan->where('code', 'basic')->where('status', 'published')->where('visibility', 'public')
                        ->whereColumn('plans.published_version_id', 'plan_versions.id'))->value('uuid');
            }

            return [
                'subscription' => $summary ? $summary + ['limits' => $limits] : null,
                'usage' => $limits ? app(WorkspaceUsage::class)->snapshot($workspaceId, keys: array_keys($limits), effective: true) : [],
                'selectionTarget' => $selectionTarget,
            ];
        }, 3);
    }

    private function subscriptionSummary(array $snapshot): ?array
    {
        $pinned = $snapshot['pinned'];
        if ($pinned['state'] !== 'available') {
            return null;
        }
        $period = SubscriptionPeriod::with('version')->findOrFail($pinned['period_id']);

        return $this->describePeriod($period) + [
            'limits' => array_intersect_key($pinned['values'], array_flip(['projects.active.max', 'developers.max', 'clients.max', 'agents.active.max'])),
        ];
    }

    public function create(User $actor, int $workspaceId, bool $platform, string $version, array $selection, int $expectedRevision, string $key, bool $manual = false): array
    {
        return DB::transaction(function () use ($actor, $workspaceId, $platform, $version, $selection, $expectedRevision, $key, $manual): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $authorityRevision = $this->authorize($actor, $workspaceId, $platform);
            abort_unless(preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422);
            $operationHash = hash('sha256', $actor->id.'|resource-preview|'.$workspaceId.'|'.$key);
            $requestHash = PlanConfiguration::hash(compact('version', 'selection', 'expectedRevision', 'platform') + ($manual ? ['manual' => true] : []));
            $previous = DB::table('plan_events')->where('operation_key_hash', $operationHash)->lockForUpdate()->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'preview_key_conflict');

                return $this->read($actor, $workspaceId, $platform, json_decode($previous->result_json, true)['previewId']);
            }
            $snapshot = $this->allocation->capture($workspaceId);
            abort_unless((int) $snapshot['subscriptionRevision'] === $expectedRevision, 409, 'subscription_revision_conflict');
            $target = $this->selections->target($version, ! $platform);
            $plan = $this->selections->plan($snapshot, $target, $selection);
            if ($manual) {
                abort_unless($platform && in_array($version, array_column(app(SubscriptionAssignments::class)->manualVersions(), 'id'), true)
                    && $snapshot['pinnedState'] === 'available' && ($snapshot['pinned']['phase'] ?? null) !== 'compatibility'
                    && $snapshot['rows']['workspace_subscriptions']->first()?->enforcement_mode === 'enforce', 409, 'manual_assignment_unavailable');
            }
            if ($snapshot['pinnedState'] !== 'available') {
                $plan['state'] = 'needs_review';
                $plan['reasonCodes'][] = 'subscription_missing_or_incomplete';
            }
            $uuid = (string) Str::uuid();
            $now = CarbonImmutable::now('UTC');
            $settings = DB::table('plan_settings')->where('id', 1)->sharedLock()->value('revision') ?? 0;
            $summary = $plan + ['manualAssignment' => $manual, 'availableAction' => $manual
                ? (array_diff($plan['reasonCodes'], ['participation_cycle_adapter_required']) ? null : 'assign-manual')
                : $this->availableAction($snapshot, $target, $plan, $platform), 'target' => $target, 'resourceFingerprint' => $snapshot['hash'], 'resourceRevisions' => $snapshot['fingerprints'], 'executionContract' => $this->selections->executionContract($snapshot, $target, $plan)];
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
                : $period->kind === 'free' && in_array($period->approval_kind, ['system', 'manual'], true)
                    && (int) $cycle?->target_version_id === (int) $period->entitlement_version_id;
            abort_unless($eligible && $cycles->count() === 1 && in_array($cycle->reason_code, ['subscription_expired', 'manual_assignment'], true), 409, 'selection_apply_unavailable');
            $preview = $this->read($actor, $workspaceId, false, $previewId);
            abort_unless($preview['target']['id'] === $period->version()->value('uuid')
                && (int) $preview['subscriptionRevision'] === $expectedRevision, 409, 'transition_preview_stale');

            $selection = $preview['selection'];
            abort_if(array_diff($preview['reasonCodes'], ['subscription_missing_or_incomplete', 'participation_cycle_adapter_required']), 422, 'selection_apply_invalid');
            abort_if(! $restorePaid && (count($selection['projects']) !== 1 || count($selection['developers']) !== 1
                || count($selection['clients']) > 3 || $selection['agents'] || $selection['invitations']), 422, 'basic_selection_invalid');

            abort_if($restorePaid && $period->service_ends_at <= CarbonImmutable::now('UTC'), 409, 'selection_apply_unavailable');
            $this->applyParticipation($actor, $workspaceId, $cycle, $preview, $expectedRevision, $operation, $restorePaid);
            $subscription->increment('revision');
            $result = ['previewId' => $previewId, 'selectionHash' => $preview['selectionHash'], 'cycleId' => $cycle->uuid, 'subscriptionRevision' => (string) $subscription->revision];
            $this->audit($actor, $workspaceId, false, $restorePaid ? 'transition.paid_restored' : 'transition.basic_selected', $result, $operationHash, $requestHash);

            return $result;
        }, 3);
    }

    /** Write only reversible participation overlays; the caller owns the workspace lock and revision. */
    public function applyParticipation(User $actor, int $workspaceId, object $cycle, array $preview, int $expectedRevision, string $operation, bool $restorePaid): void
    {
        $selection = $preview['selection'];
        $projects = DB::table('projects')->where('workspace_id', $workspaceId)->whereIn('uuid', $selection['projects'])
            ->whereNull('deleted_at')->where('status', 'active')->orderBy('id')->get(['id', 'uuid']);
        $clients = DB::table('project_clients')->whereIn('project_id', $projects->pluck('id'))
            ->where('active', true)->whereIn('uuid', $preview['clientParticipations'])->orderBy('id')->get(['id', 'uuid']);
        $members = DB::table('workspace_members')->where('workspace_id', $workspaceId)->where('status', 'active')->where('role', 'admin')
            ->whereIn('id', $selection['developers'])->orderBy('id')->get(['id']);
        abort_unless($projects->count() === count($selection['projects'])
            && $clients->count() === count($preview['clientParticipations'])
            && $members->count() + 1 === count($selection['developers']), 409, 'transition_preview_stale');

        $agents = DB::table('external_agents')->where('workspace_id', $workspaceId)->where('active', true)->whereNull('deleted_at')
            ->whereIn('uuid', $selection['agents'])->whereExists(fn ($query) => $query->selectRaw('1')->from('agent_project_grants as grant_row')
            ->join('project_members as assignment', function ($join): void {
                $join->on('assignment.workspace_id', '=', 'grant_row.workspace_id')
                    ->on('assignment.external_agent_id', '=', 'grant_row.external_agent_id')->on('assignment.project_id', '=', 'grant_row.project_id');
            })->whereColumn('grant_row.external_agent_id', 'external_agents.id')
            ->where('grant_row.workspace_id', $workspaceId)->whereIn('grant_row.project_id', $projects->pluck('id')))
            ->orderBy('id')->get(['id', 'uuid']);
        abort_unless($agents->count() === count($selection['agents']), 422, 'selection_agent_participation_required');
        $invites = DB::table('developer_invitations')->where('workspace_id', $workspaceId)->whereIn('uuid', $selection['invitations'])
            ->whereNotNull('token_hash')->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))
            ->orderBy('id')->get();
        foreach ($invites as $invite) {
            $assigned = json_decode($invite->project_ids, true, 16, JSON_THROW_ON_ERROR);
            abort_if(array_diff($assigned, $projects->pluck('id')->all()), 422, 'selection_invitation_projects_held');
            app(InvitationOccupancy::class)->sync($invite->id);
        }
        $reservations = DB::table('entitlement_capacity_reservations')->where('workspace_id', $workspaceId)->where('key', 'developers.max')
            ->whereIn('developer_invitation_id', $invites->pluck('id'))->where('state', 'held');
        abort_unless($invites->count() === count($selection['invitations']) && $reservations->count() === $invites->count(), 409, 'selection_invitation_unavailable');

        $at = CarbonImmutable::now('UTC');
        $now = $at->format('Y-m-d H:i:s.u');
        $scope = ['workspace_id' => $workspaceId, 'cycle_id' => $cycle->id];
        DB::table('workspace_entitlement_resource_states')->where($scope)
            ->whereIn('resource_kind', ['project', 'project_client', 'workspace_member', 'external_agent'])->where('state', 'released')
            ->update(['state' => 'held', 'reviewed_revision' => $expectedRevision, 'confirmed_by' => $actor->id, 'reason_code' => 'selection_replaced', 'changed_at' => $now]);
        foreach (['project' => $projects, 'project_client' => $clients, 'workspace_member' => $members, 'external_agent' => $agents] as $kind => $resources) {
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
        DB::table('entitlement_capacity_reservations')->where('workspace_id', $workspaceId)
            ->where('restored_cycle_id', $cycle->id)->update(['restored_cycle_id' => null]);
        $reservations->update(['restored_cycle_id' => $cycle->id]);
        if ($restorePaid) {
            DB::table('workspace_execution_epochs')->where('workspace_id', $workspaceId)->where('state', 'ready')
                ->update(['state' => 'blocked', 'epoch' => DB::raw('epoch + 1'), 'revision' => DB::raw('revision + 1'), 'blocked_at' => $now, 'resume_at' => null]);
        }
        $watermarks = json_decode($cycle->resource_high_watermarks_json, true, 16, JSON_THROW_ON_ERROR);
        $watermarks['project'] = max($watermarks['project'] ?? 0, (int) DB::table('projects')->where('workspace_id', $workspaceId)->orderByDesc('id')->lockForUpdate()->value('id'));
        foreach (['workspace_member' => 'workspace_members', 'external_agent' => 'external_agents'] as $kind => $table) {
            $watermarks[$kind] = max($watermarks[$kind] ?? 0, (int) DB::table($table)->where('workspace_id', $workspaceId)->orderByDesc('id')->lockForUpdate()->value('id'));
        }
        $watermarks['project_client'] = max($watermarks['project_client'] ?? 0, (int) DB::table('project_clients')
            ->whereIn('project_id', DB::table('projects')->where('workspace_id', $workspaceId)->select('id'))->max('id'));
        DB::table('workspace_suspension_cycles')->where('id', $cycle->id)->increment('revision', 1, [
            'resource_high_watermarks_json' => json_encode($watermarks, JSON_THROW_ON_ERROR),
        ]);
    }

    public function rearm(User $actor, int $workspaceId, int $revision, array $groups, string $key): array
    {
        sort($groups);

        return DB::transaction(function () use ($actor, $workspaceId, $revision, $groups, $key): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $this->authorize($actor, $workspaceId, false);
            abort_unless($groups && array_is_list($groups) && count(array_unique($groups)) === count($groups)
                && ! array_diff($groups, array_keys(ExecutionBarrier::FEATURES))
                && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422);
            $operationHash = hash('sha256', $actor->id.'|rearm|'.$workspaceId.'|'.$key);
            $requestHash = PlanConfiguration::hash(compact('revision', 'groups'));
            $previous = DB::table('plan_events')->where('operation_key_hash', $operationHash)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'preview_key_conflict');

                return json_decode($previous->result_json, true, 16, JSON_THROW_ON_ERROR);
            }

            $subscription = WorkspaceSubscription::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->revision === $revision, 409, 'subscription_revision_conflict');
            $cycles = DB::table('workspace_suspension_cycles')->where('workspace_id', $workspaceId)->where('state', 'open')->limit(2)->get();
            abort_unless($cycles->count() === 1, 409, 'paid_restore_confirmation_required');
            $cycle = $cycles->first();
            $restore = DB::table('plan_events')->where('workspace_id', $workspaceId)->where('action', 'transition.paid_restored')->latest('id')->first();
            $restored = $restore ? json_decode($restore->result_json, true, 16, JSON_THROW_ON_ERROR) : [];
            abort_unless(($restored['cycleId'] ?? null) === $cycle->uuid
                && (int) ($restored['subscriptionRevision'] ?? 0) === $revision, 409, 'paid_restore_confirmation_required');
            $snapshot = app(EntitlementResolver::class)->resolve($workspaceId, current: true, inspectSuspended: true);
            abort_unless($snapshot['state'] === 'available' && $snapshot['phase'] === 'paid', 409, 'paid_restore_confirmation_required');

            foreach ($groups as $group) {
                foreach (ExecutionBarrier::FEATURES[$group] as $feature) {
                    abort_unless(($snapshot['values'][$feature]['value'] ?? null) === true, 422, 'subscription_feature_unavailable');
                }
            }
            $now = CarbonImmutable::now('UTC');
            abort_unless($snapshot['valid_until'] !== null && $now < CarbonImmutable::parse($snapshot['valid_until'], 'UTC'), 409, 'subscription_revision_conflict');
            $updated = DB::table('workspace_execution_epochs')->where('workspace_id', $workspaceId)
                ->whereIn('feature_group', $groups)->whereIn('state', ['blocked', 'needs_confirmation'])
                ->update(['state' => 'ready', 'epoch' => DB::raw('epoch + 1'), 'revision' => DB::raw('revision + 1'), 'resume_at' => $now->format('Y-m-d H:i:s.u')]);
            abort_unless($updated === count($groups), 409, 'subscription_revision_conflict');
            $subscription->increment('revision');
            $result = ['cycleId' => $cycle->uuid, 'groups' => $groups, 'subscriptionRevision' => (string) $subscription->revision];
            $this->audit($actor, $workspaceId, false, 'transition.integrations_rearmed', $result, $operationHash, $requestHash);

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
