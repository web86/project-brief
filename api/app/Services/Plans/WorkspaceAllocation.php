<?php

namespace App\Services\Plans;

use App\Services\IntegrationWrites;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class WorkspaceAllocation
{
    public const LIMIT = 250;

    /** Caller acquires workspace first. Only allowlisted metadata is fingerprinted; no task/chat/file content. */
    public function capture(int $workspaceId, bool $lock = true): array
    {
        if ($lock) {
            IntegrationWrites::lockWorkspace($workspaceId);
        }
        $tables = [
            'projects' => ['id', 'uuid', 'workspace_id', 'status', 'deleted_at', 'updated_at'],
            'workspace_members' => ['id', 'user_id', 'role', 'status', 'is_project_superadmin', 'can_create_projects', 'updated_at'],
            'project_members' => ['id', 'project_id', 'workspace_member_id', 'external_agent_id', 'permissions'],
            'project_clients' => ['id', 'uuid', 'project_id', 'active', 'entitlement_identity_id', 'workspace_client_id', 'email', 'updated_at'],
            'external_agents' => ['id', 'uuid', 'active', 'permissions', 'deleted_at', 'updated_at'],
            'developer_invitations' => ['id', 'uuid', 'email', 'invited_by', 'project_scoped', 'project_ids', 'expires_at', 'accepted_at', 'revoked_at'],
            'entitlement_client_observations' => ['project_client_id', 'identity_id', 'input_hash', 'state', 'revision'],
            'entitlement_client_aliases' => ['id', 'identity_id', 'state', 'provenance_revision'],
            'entitlement_capacity_reservations' => ['id', 'developer_invitation_id', 'identity_hash', 'state', 'expires_at'],
            'entitlement_client_identities' => ['id', 'uuid', 'provenance_kind', 'revision'],
            'agent_credential_links' => ['id', 'uuid', 'integration_token_id', 'external_agent_id'],
            'agent_project_grants' => ['external_agent_id', 'project_id'],
            'integration_source_projects' => ['id', 'integration_source_id', 'project_id'],
            'integration_tokens' => ['id', 'uuid', 'scopes', 'external_agent_id', 'agent_shared', 'integration_source_id', 'provisioning_enabled', 'expires_at', 'revoked_at', 'updated_at'],
            'webhook_endpoints' => ['id', 'uuid', 'enabled', 'events', 'external_agent_id', 'secret_version', 'project_filter', 'updated_at'],
            'webhook_endpoint_projects' => ['webhook_endpoint_id', 'project_id'],
            'integration_sources' => ['id', 'uuid', 'sso_enabled', 'archived_at', 'updated_at'],
            'plan_inventory_items' => ['workspace_id', 'revision', 'state'],
            'workspace_subscriptions' => ['id', 'uuid', 'current_period_id', 'revision', 'approved_extra_seats', 'enforcement_mode'],
            'subscription_periods' => ['id', 'uuid', 'entitlement_version_id', 'policy_version_id', 'fallback_version_id', 'starts_at', 'service_ends_at', 'grace_ends_at', 'superseded_at'],
            'workspace_entitlement_overrides' => ['id', 'revision', 'value_json', 'starts_at', 'ends_at'],
            'workspace_suspension_cycles' => ['id', 'revision', 'state'],
            'workspace_entitlement_resource_states' => ['id', 'cycle_id', 'resource_kind', 'resource_id', 'state', 'reviewed_revision'],
            'workspace_execution_epochs' => ['feature_group', 'epoch', 'state', 'revision'],
        ];
        $rows = [];
        foreach ($tables as $table => $columns) {
            $query = DB::table($table);
            if ($table === 'project_clients') {
                $query->whereIn('project_id', DB::table('projects')->where('workspace_id', $workspaceId)->select('id'));
            } else {
                $query->where('workspace_id', $workspaceId);
            }
            $rows[$table] = $query->orderBy($columns[0])->when(count($columns) > 1, fn ($q) => $q->orderBy($columns[1]))->limit(self::LIMIT + 1)->when($lock, fn (Builder $q) => $q->lockForUpdate())->get($columns);
            abort_if($rows[$table]->count() > self::LIMIT, 422, 'selection_resource_bound');
        }
        $fingerprints = [];
        foreach ($rows as $table => $items) {
            $fingerprints[$table] = hash_hmac('sha256', $items->toJson(), (string) config('app.key'));
        }
        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first(['uuid', 'updated_at']);
        $inputs = app(ClientCountingIdentities::class)->inputs($workspaceId, $rows['project_clients'], $lock);
        $fingerprints['client_provenance'] = PlanConfiguration::hash(array_map(fn ($i) => $i['hash'], $inputs));
        $usage = app(WorkspaceUsage::class)->snapshot($workspaceId, $lock);
        $pinned = app(EntitlementResolver::class)->resolve($workspaceId, current: $lock);
        $fingerprints['pinned'] = PlanConfiguration::hash($pinned);
        $fingerprints['usage'] = PlanConfiguration::hash(array_map(fn ($m) => array_intersect_key($m, array_flip(['state', 'count', 'reason_codes', 'identity_fingerprint'])), $usage));

        return ['workspaceInternalId' => $workspaceId, 'pinnedState' => $pinned['state'], 'pinnedReason' => $pinned['reason'], 'pinned' => $pinned, 'workspaceId' => $workspace->uuid, 'rows' => $rows, 'fingerprints' => $fingerprints, 'hash' => PlanConfiguration::hash($fingerprints), 'usage' => $usage, 'subscriptionRevision' => (string) ($rows['workspace_subscriptions']->first()?->revision ?? 0)];
    }
}
