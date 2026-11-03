<?php

namespace App\Services\Plans;

use App\Models\Plans\PlanVersion;
use App\Services\DeveloperInvitations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ResourceSelections
{
    public function options(array $snapshot, bool $owner): array
    {
        $r = $snapshot['rows'];
        $projects = $r['projects']->filter(fn ($p) => ! $p->deleted_at && $p->status === 'active');
        $members = $r['workspace_members']->filter(fn ($m) => $m->status === 'active' && in_array($m->role, ['owner', 'admin'], true));
        $agents = $r['external_agents']->filter(fn ($a) => $a->active && ! $a->deleted_at);
        $clients = $r['project_clients']->filter(fn ($c) => $c->active && $projects->contains('id', $c->project_id));
        $identities = $r['entitlement_client_identities']->keyBy('id');
        $invites = $r['developer_invitations']->filter(fn ($i) => ! $i->accepted_at && ! $i->revoked_at && CarbonImmutable::parse($i->expires_at, 'UTC')->isFuture());
        $validity = app(InvitationOccupancy::class)->managerValidity($invites, $snapshot['workspaceInternalId'], true);
        $holds = $r['entitlement_capacity_reservations']->keyBy('developer_invitation_id');
        $invites = $invites->filter(fn ($i) => ($validity[$i->id] ?? null) === true && $holds->get($i->id)?->state !== 'suspended');
        $names = $owner ? DB::table('projects')->whereIn('id', $projects->pluck('id'))->pluck('title', 'id') : collect();
        $humans = $owner ? DB::table('users')->whereIn('id', $members->pluck('user_id'))->pluck('name', 'id') : collect();
        $clientNames = $owner ? DB::table('project_clients')->whereIn('id', $clients->pluck('id'))->pluck('name', 'id') : collect();
        $agentNames = $owner ? DB::table('external_agents')->whereIn('id', $agents->pluck('id'))->pluck('name', 'id') : collect();
        $projectIds = $projects->pluck('uuid', 'id');

        return ['projects' => $projects->map(fn ($p) => ['id' => $p->uuid, 'label' => $names->get($p->id, $p->uuid)])->values()->all(),
            'developers' => $members->map(fn ($m) => ['id' => (string) $m->id, 'owner' => $m->role === 'owner', 'label' => $humans->get($m->user_id, (string) $m->id)])->values()->all(),
            'clients' => $clients->groupBy('entitlement_identity_id')->map(function ($links, $id) use ($identities, $projectIds, $clientNames): array {
                $identity = $identities->get($id);

                $label = $links->map(fn ($client) => $clientNames->get($client->id))->filter()->unique()->implode(', ');

                return ['id' => $identity?->uuid, ...($label !== '' ? ['label' => $label] : []), 'state' => $identity ? 'mapped' : 'needs_review', 'projectIds' => $links->map(fn ($c) => $projectIds->get($c->project_id))->unique()->values()->all()];
            })->values()->all(),
            'agents' => $agents->map(fn ($a) => ['id' => $a->uuid, ...($agentNames->has($a->id) ? ['label' => $agentNames->get($a->id)] : [])])->values()->all(),
            'invitations' => $invites->map(fn ($i) => ['id' => $i->uuid, 'expiresAt' => $i->expires_at])->values()->all()];
    }

    public function target(string $uuid, bool $public = false): array
    {
        $version = PlanVersion::with(['plan' => fn ($q) => $q->sharedLock()])->sharedLock()->where('uuid', $uuid)->where('state', 'published')->whereIn('access_class', ['free', 'paid'])->when($public, fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('visibility', 'public')))->firstOrFail();
        abort_if($version->plan->status === 'archived', 422);
        $config = app(PlanCatalog::class)->configuration($version);
        app(PlanConfiguration::class)->validate($config);
        abort_unless(hash_equals($version->content_hash, PlanConfiguration::versionHash($config)), 409);

        return ['id' => $version->uuid, 'accessClass' => $version->access_class, 'hash' => $version->content_hash, 'revision' => (string) $version->revision, 'catalogRevision' => (string) $version->plan->revision, 'limits' => $config['entitlements'] + ['developers.max' => app(EntitlementRegistry::class)->seats($config['developer_seats'])]];
    }

    /** Computes a proposed set only; it cannot mutate statuses, memberships, credentials, cycles or epochs. */
    public function plan(array $snapshot, array $target, array $selection): array
    {
        $keys = ['projects', 'developers', 'clients', 'agents', 'invitations'];
        abort_if(array_diff(array_keys($selection), $keys), 422);
        foreach ($keys as $key) {
            abort_unless(isset($selection[$key]) && is_array($selection[$key]) && array_is_list($selection[$key]) && count($selection[$key]) <= WorkspaceAllocation::LIMIT && count(array_unique($selection[$key])) === count($selection[$key]), 422);
            foreach ($selection[$key] as $id) {
                abort_unless(is_string($id) && strlen($id) <= 36, 422);
            }
            sort($selection[$key]);
        }
        app(PlanConfiguration::class)->bounded($selection);
        $options = $this->options($snapshot, false);
        foreach ($keys as $key) {
            abort_if(array_diff($selection[$key], array_column($options[$key], 'id')), 422, 'selection_resource_unavailable');
        }
        $owners = array_column(array_filter($options['developers'], fn ($m) => $m['owner']), 'id');
        abort_if(array_diff($owners, $selection['developers']), 422, 'selection_owner_required');
        $reasons = count($owners) === 1 ? [] : ['ownership_review_required'];
        $clientOptions = collect($options['clients'])->filter(fn ($c) => count(array_intersect($c['projectIds'], $selection['projects'])) > 0);
        abort_if(array_diff($selection['clients'], $clientOptions->pluck('id')->all()), 422, 'selection_client_participation_required');
        foreach (['projects.active.max', 'developers.max', 'clients.max', 'agents.active.max'] as $key) {
            if ($snapshot['usage'][$key]['state'] !== 'ready') {
                $reasons = [...$reasons, ...$snapshot['usage'][$key]['reason_codes']];
            }
        }
        $r = $snapshot['rows'];
        $knownUsers = DB::table('users')->whereIn(DB::raw('LOWER(TRIM(email))'), $r['developer_invitations']->map(fn ($i) => DeveloperInvitations::normalizeEmail($i->email))->all())->sharedLock()->get(['id', 'email'])->groupBy(fn ($u) => DeveloperInvitations::normalizeEmail($u->email));
        $occupied = [];
        foreach ($r['workspace_members']->whereIn('id', $selection['developers']) as $m) {
            $occupied['user:'.$m->user_id] = true;
        }
        foreach ($r['developer_invitations']->whereIn('uuid', $selection['invitations']) as $i) {
            $users = $knownUsers->get(DeveloperInvitations::normalizeEmail($i->email));
            if ($users && $users->count() > 1) {
                $reasons[] = 'invitation_accounting_review_required';
            }
            $occupied[$users?->first() ? 'user:'.$users->first()->id : 'email:'.app(InvitationOccupancy::class)->identity($i->email)] = true;
        }
        $counts = ['projects.active.max' => count($selection['projects']), 'developers.max' => count($occupied), 'clients.max' => count($selection['clients']), 'agents.active.max' => count($selection['agents'])];
        $excess = [];
        foreach ($counts as $key => $count) {
            $limit = $target['limits'][$key];
            $excess[$key] = $limit['kind'] === 'unlimited' ? 0 : max(0, $count - $limit['value']);
            if ($excess[$key] > 0) {
                $reasons[] = 'selection_capacity_exceeded';
            }
        }
        $held = [];
        foreach ($keys as $key) {
            $held[$key] = array_values(array_diff(array_filter(array_column($options[$key], 'id')), $selection[$key]));
        }
        $premium = [];
        foreach (['api.agents.enabled', 'api.external.enabled', 'webhooks.enabled', 'sso.enabled'] as $key) {
            $premium[$key] = $target['limits'][$key]['value'] ? 'retained_subject_to_existing_permissions' : 'would_hold_execution';
        }
        $premium['branding.level'] = ['target' => $target['limits']['branding.level']['value'], 'state' => 'field_policy_review_required'];

        $selectedProjects = $r['projects']->whereIn('uuid', $selection['projects'])->pluck('id');
        $selectedIdentities = $r['entitlement_client_identities']->whereIn('uuid', $selection['clients'])->pluck('id');
        $clientParticipations = $r['project_clients']->filter(fn ($c) => $c->active && $selectedProjects->contains($c->project_id) && $selectedIdentities->contains($c->entitlement_identity_id))->pluck('uuid')->sort()->values()->all();

        return ['clientParticipations' => $clientParticipations, 'state' => $reasons ? 'needs_review' : 'ready', 'selection' => $selection, 'selectionHash' => PlanConfiguration::hash($selection), 'counts' => $counts, 'limits' => $target['limits'], 'excess' => $excess, 'wouldHold' => $held, 'wouldRelease' => $selection, 'premium' => $premium, 'reasonCodes' => array_values(array_unique($reasons)), 'safeActions' => ['revoke', 'cancel', 'recovery', 'authorized_history', 'pdf_docx_export', 'magic_link', 'basic_login', 'mfa'], 'previewOnly' => true, 'executorAvailable' => false];
    }

    /** Deterministic future batch contract only. No method executes these proposed operations. */
    public function executionContract(array $snapshot, array $target, array $plan): array
    {
        $operations = [];
        foreach (['hold' => $plan['wouldHold'], 'retain' => $plan['wouldRelease']] as $action => $groups) {
            foreach ($groups as $kind => $ids) {
                sort($ids);
                foreach ($ids as $id) {
                    $operations[] = ['kind' => $kind, 'resourceId' => $id, 'action' => $action, 'receiptKey' => PlanConfiguration::hash([$snapshot['workspaceId'], $snapshot['subscriptionRevision'], $target['hash'], $plan['selectionHash'], $kind, $id, $action])];
                }
            }
        }

        return ['schemaVersion' => 1, 'executable' => false, 'state' => 'preparation_only', 'workspaceId' => $snapshot['workspaceId'], 'expectedRevision' => $snapshot['subscriptionRevision'], 'resourceFingerprint' => $snapshot['hash'], 'targetHash' => $target['hash'], 'selectionHash' => $plan['selectionHash'], 'clientParticipations' => $plan['clientParticipations'], 'approvalRequired' => ['target_version', 'pinned_policy', 'transition_rule', 'fresh_authority', 'branding_field_policy', 'commercial_activation'], 'batchSize' => 100, 'batches' => array_chunk($operations, 100), 'resumeRule' => 'revalidate_workspace_revision_and_receipts_before_each_batch', 'compensationRule' => 'restore_only_owned_overlay_revision_never_manual_business_state', 'terminalOutcomes' => ['applied', 'failed', 'needs_review', 'compensated'], 'auditRequired' => true];
    }
}
