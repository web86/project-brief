<?php

namespace App\Services\Plans;

use App\Models\User;
use App\Services\IntegrationWrites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountingReview
{
    public function listing(int $workspaceId): array
    {
        $rows = DB::table('project_clients as c')->join('projects as p', 'p.id', '=', 'c.project_id')->leftJoin('entitlement_client_observations as o', 'o.project_client_id', '=', 'c.id')->where('p.workspace_id', $workspaceId)->orderBy('c.id')->limit(251)->select(['c.*', 'o.state as accounting_state', 'o.revision as accounting_revision', 'o.reason_codes'])->get();
        abort_if($rows->count() > 250, 422, 'accounting_review_bound');
        $inputs = app(ClientCountingIdentities::class)->inputs($workspaceId, $rows, true);

        $identities = DB::table('entitlement_client_identities')->where('workspace_id', $workspaceId)->whereIn('id', $rows->pluck('entitlement_identity_id')->filter())->pluck('uuid', 'id');

        return $rows->map(fn ($c) => ['clientId' => $c->uuid, 'identityId' => $identities->get($c->entitlement_identity_id), 'revision' => (string) ($c->accounting_revision ?? 0), 'inputHash' => $inputs[$c->id]['hash'], 'state' => $c->accounting_state ?? 'needs_review', 'provenance' => ['verifiedSources' => count($inputs[$c->id]['anchors']), 'contactHint' => (bool) $inputs[$c->id]['contact'], 'emailFallback' => $inputs[$c->id]['email'] !== '', 'contradiction' => $inputs[$c->id]['invalid']], 'reasonCodes' => $c->reason_codes ? json_decode($c->reason_codes, true) : ['client_mapping_incomplete']])->all();
    }

    public function review(User $actor, int $workspaceId, bool $platform, string $clientUuid, string $action, int $revision, string $inputHash, string $reason, string $key): array
    {
        return DB::transaction(function () use ($actor, $workspaceId, $platform, $clientUuid, $action, $revision, $inputHash, $reason, $key): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            app(FrozenTransitions::class)->authorize($actor, $workspaceId, $platform);
            abort_unless(in_array($action, ['confirm', 'separate'], true) && preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $reason) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key), 422);
            $client = DB::table('project_clients')->whereIn('project_id', DB::table('projects')->where('workspace_id', $workspaceId)->select('id'))->where('uuid', $clientUuid)->lockForUpdate()->first();
            abort_unless($client, 404);
            $facts = app(ClientCountingIdentities::class)->inputs($workspaceId, collect([$client]), true)[$client->id];
            $observation = DB::table('entitlement_client_observations')->where('project_client_id', $client->id)->lockForUpdate()->first();
            $operationHash = hash('sha256', $actor->id.'|accounting-review|'.$workspaceId.'|'.$key);
            $requestHash = PlanConfiguration::hash(compact('clientUuid', 'action', 'revision', 'inputHash', 'reason', 'platform'));
            $previous = DB::table('plan_events')->where('operation_key_hash', $operationHash)->lockForUpdate()->first();
            if ($previous) {
                $result = json_decode($previous->result_json, true);
                abort_unless(hash_equals($previous->request_hash, $requestHash) && (int) $observation?->revision === $result['afterRevision'] && hash_equals($facts['hash'], $inputHash), 409, 'accounting_review_stale');

                return $result;
            }
            abort_unless((int) ($observation?->revision ?? 0) === $revision && hash_equals($facts['hash'], $inputHash), 409, 'accounting_review_stale');
            abort_if($facts['invalid'] || count($facts['anchors']) > 1, 422, 'accounting_evidence_requires_review');
            $identity = DB::table('entitlement_client_identities')->where('workspace_id', $workspaceId)->where('id', $client->entitlement_identity_id)->lockForUpdate()->first();
            abort_unless($identity, 422, 'accounting_mapping_required');
            foreach ($facts['anchors'] as $anchor) {
                $hashes = app(ClientCountingIdentities::class);
                $alias = DB::table('entitlement_client_aliases')->where('workspace_id', $workspaceId)->where('alias_kind', 'verified_source')->where('issuer_hash', $hashes->hash('issuer', $anchor['issuer']))->where('alias_hash', $hashes->hash('verified_source', $anchor['value']))->lockForUpdate()->first();
                abort_unless($alias && $alias->state === 'usable' && (int) $alias->identity_id === (int) $identity->id, 422, 'verified_identity_requires_review');
            }
            if ($action === 'separate') {
                // Splitting a proven shared subject is not an accounting repair.
                abort_if(count($facts['anchors']) > 0, 422, 'verified_identity_split_requires_review');
                $old = $identity->uuid;
                $newId = DB::table('entitlement_client_identities')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'provenance_kind' => 'isolated', 'revision' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                DB::table('project_clients')->where('id', $client->id)->update(['entitlement_identity_id' => $newId]);
                DB::table('entitlement_client_aliases')->where('workspace_id', $workspaceId)->where('identity_id', $identity->id)->where('alias_kind', '<>', 'verified_source')->update(['identity_id' => null, 'state' => 'ambiguous', 'provenance_revision' => DB::raw('provenance_revision + 1')]);
                $identity = DB::table('entitlement_client_identities')->find($newId);
            }
            DB::table('entitlement_client_observations')->updateOrInsert(['project_client_id' => $client->id], ['workspace_id' => $workspaceId, 'identity_id' => $identity->id, 'input_hash' => $facts['hash'], 'state' => 'ready', 'reason_codes' => '["accounting_review_confirmed"]', 'revision' => $revision + 1, 'observed_at' => now('UTC')]);
            $usage = app(WorkspaceUsage::class)->snapshot($workspaceId, true);
            $ready = collect($usage)->only(['projects.active.max', 'developers.max', 'clients.max', 'agents.active.max'])->every(fn ($m) => $m['state'] === 'ready');
            DB::table('plan_inventory_items')->where('workspace_id', $workspaceId)->update(['state' => $ready ? 'processed' : 'needs_review', 'revision' => DB::raw('revision + 1'), 'reason_codes' => json_encode($ready ? [] : ['accounting_review_required']), 'observed_at' => now('UTC')]);
            $result = ['clientId' => $clientUuid, 'identityId' => $identity->uuid, 'previousIdentityId' => $old ?? $identity->uuid, 'beforeRevision' => $revision, 'afterRevision' => $revision + 1, 'action' => $action, 'reason' => $reason, 'authenticationChanged' => false];
            app(FrozenTransitions::class)->audit($actor, $workspaceId, $platform, 'accounting.reviewed', $result, $operationHash, $requestHash);

            return $result;
        }, 3);
    }
}
