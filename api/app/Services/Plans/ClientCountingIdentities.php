<?php

namespace App\Services\Plans;

use App\Services\DeveloperInvitations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientCountingIdentities
{
    public function hash(string $namespace, string $value): string
    {
        return hash_hmac('sha256', 'client-accounting-v1|'.$namespace.'|'.$value, (string) config('app.key'));
    }

    /** Only persisted, consumed source-bound handoffs are evidence; contacts remain accounting hints. */
    public function inputs(int $workspaceId, Collection $clients, bool $current = false): array
    {
        $contacts = DB::table('workspace_clients')->where('workspace_id', $workspaceId)->whereIn('id', $clients->pluck('workspace_client_id')->filter())->when($current, fn ($q) => $q->lockForUpdate())->get(['id', 'uuid'])->keyBy('id');
        $proofs = DB::table('client_sso_handoffs as h')->join('client_sso_bindings as b', function ($join): void {
            $join->on('b.id', '=', 'h.client_sso_binding_id')->on('b.workspace_id', '=', 'h.workspace_id')->on('b.integration_source_id', '=', 'h.integration_source_id');
        })->where('h.workspace_id', $workspaceId)->whereIn('h.project_client_id', $clients->pluck('id'))->whereNotNull('h.consumed_at')->whereNull('h.revoked_at')
            ->select(['h.project_client_id', 'h.project_id', 'b.integration_source_id', 'b.external_hash', 'b.email_hash'])->distinct()->when($current, fn ($q) => $q->lockForUpdate())->get()->groupBy('project_client_id');
        $result = [];
        foreach ($clients as $c) {
            $email = DeveloperInvitations::normalizeEmail($c->email ?? '');
            $anchors = [];
            $invalid = false;
            foreach ($proofs->get($c->id, collect()) as $p) {
                if ((int) $p->project_id !== (int) $c->project_id || ! hash_equals($p->email_hash, hash('sha256', $email))) {
                    $invalid = true;

                    continue;
                }
                $anchors['source:'.$p->integration_source_id.'|'.$p->external_hash] = ['issuer' => 'source:'.$p->integration_source_id, 'value' => $p->external_hash];
            }
            ksort($anchors);
            $contact = $contacts->get($c->workspace_client_id)?->uuid;
            $facts = ['email' => $email, 'contact' => $contact, 'proofs' => array_keys($anchors), 'invalid' => $invalid || ($c->workspace_client_id && ! $contact)];
            $result[$c->id] = $facts + ['anchors' => array_values($anchors), 'hash' => $this->hash('input', PlanConfiguration::hash($facts))];
        }

        return $result;
    }

    /** Workspace-first caller; bounded chunk and stable links make interrupted replay harmless. */
    public function reconcile(int $workspaceId, int $afterId, int $cutoffId, int $limit = 200): array
    {
        $rows = DB::table('project_clients as c')->join('projects as p', 'p.id', '=', 'c.project_id')->where('p.workspace_id', $workspaceId)->where('c.id', '>', $afterId)->where('c.id', '<=', $cutoffId)->orderBy('c.id')->limit($limit)->select('c.*')->lockForUpdate()->get();
        $inputs = $this->inputs($workspaceId, $rows);
        $mapped = 0;
        foreach ($rows as $row) {
            $mapped += $this->map($workspaceId, $row, $inputs[$row->id]) ? 1 : 0;
        }

        return ['last_id' => $rows->last()?->id ?? $cutoffId, 'processed' => $rows->count(), 'new_mappings' => $mapped, 'done' => $rows->count() < $limit || ($rows->last()?->id ?? 0) >= $cutoffId];
    }

    private function map(int $workspaceId, object $c, array $facts): bool
    {
        $observation = DB::table('entitlement_client_observations')->where('project_client_id', $c->id)->first();
        $reasons = [];
        if ($facts['invalid'] || count($facts['anchors']) > 1) {
            $reasons[] = 'source_identity_review_required';
        }
        $proof = count($facts['anchors']) === 1 ? $facts['anchors'][0] : null;
        $kind = $proof ? 'verified_source' : ($facts['contact'] ? 'contact_anchor' : ($facts['email'] !== '' ? 'email_fallback' : 'isolated'));
        $identity = $c->entitlement_identity_id ? DB::table('entitlement_client_identities')->where('workspace_id', $workspaceId)->find($c->entitlement_identity_id) : null;
        if ($c->entitlement_identity_id && ! $identity) {
            DB::table('entitlement_client_observations')->updateOrInsert(['project_client_id' => $c->id], ['workspace_id' => $workspaceId, 'identity_id' => null, 'input_hash' => $facts['hash'], 'state' => 'needs_review', 'reason_codes' => '["foreign_accounting_mapping"]', 'revision' => ($observation?->revision ?? 0) + 1, 'observed_at' => now('UTC')]);

            return false;
        }
        if ($identity && $observation && (int) $observation->identity_id === (int) $identity->id && $observation->input_hash === $facts['hash'] && $observation->state === 'ready' && in_array('accounting_review_confirmed', json_decode($observation->reason_codes, true), true)) {
            return false;
        }
        if ($identity && (! $observation || (int) $observation->identity_id !== (int) $identity->id || $observation->input_hash !== $facts['hash'])) {
            $reasons[] = 'mapping_provenance_review_required';
        }
        if ($observation?->state === 'needs_review') {
            $reasons = array_merge($reasons, json_decode($observation->reason_codes, true, 16, JSON_THROW_ON_ERROR));
        }
        $aliases = [];
        if ($proof) {
            $aliases[] = ['verified_source', $proof['issuer'], $proof['value']];
        }
        if ($facts['contact']) {
            $aliases[] = ['contact_anchor', 'workspace', $facts['contact']];
        }
        if ($facts['email'] !== '') {
            $aliases[] = ['email', 'normalized-email', $facts['email']];
        }
        if (! $identity && ! $proof) {
            $candidates = [];
            foreach ($aliases as [$aliasKind, $issuer, $value]) {
                $candidateAlias = $this->alias($workspaceId, $aliasKind, $issuer, $value);
                if ($candidateAlias?->state === 'ambiguous') {
                    $reasons[] = 'ambiguous_client_alias';
                } elseif ($candidateAlias?->identity_id) {
                    $candidates[$candidateAlias->identity_id] = true;
                }
            }
            if (count($candidates) > 1) {
                $reasons[] = 'ambiguous_client_alias';
            }
        }
        foreach ($aliases as [$aliasKind, $issuer, $value]) {
            if ($identity || $reasons) {
                break;
            }
            $alias = $this->alias($workspaceId, $aliasKind, $issuer, $value);
            if ($alias?->state === 'ambiguous') {
                $reasons[] = 'ambiguous_client_alias';
                break;
            }
            if ($alias?->identity_id) {
                $candidate = DB::table('entitlement_client_identities')->where('workspace_id', $workspaceId)->find($alias->identity_id);
                if ($proof && $aliasKind !== 'verified_source') {
                    // A shared mailbox/contact does not establish equivalence to a different proven subject.
                    $reasons[] = 'ambiguous_client_alias';
                    break;
                }
                $identity = $candidate;
            }
        }
        $new = ! $c->entitlement_identity_id;
        if (! $identity) {
            $id = DB::table('entitlement_client_identities')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'provenance_kind' => $reasons ? 'isolated' : $kind, 'revision' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $identity = DB::table('entitlement_client_identities')->find($id);
        }
        if (! $c->entitlement_identity_id) {
            DB::table('project_clients')->where('id', $c->id)->update(['entitlement_identity_id' => $identity->id]);
        }
        if (! $aliases) {
            $reasons[] = 'client_anchor_missing';
        }
        foreach ($aliases as [$aliasKind, $issuer, $value]) {
            $alias = $this->alias($workspaceId, $aliasKind, $issuer, $value);
            if ($alias && ($alias->state === 'ambiguous' || (int) $alias->identity_id !== (int) $identity->id)) {
                if ($alias->state !== 'ambiguous') {
                    DB::table('entitlement_client_aliases')->where('id', $alias->id)->update(['state' => 'ambiguous', 'identity_id' => null, 'provenance_revision' => $alias->provenance_revision + 1]);
                }
                $reasons[] = 'ambiguous_client_alias';
            } elseif (! $alias) {
                DB::table('entitlement_client_aliases')->insert(['workspace_id' => $workspaceId, 'alias_kind' => $aliasKind, 'issuer_hash' => $this->hash('issuer', $issuer), 'alias_hash' => $this->hash($aliasKind, $value), 'identity_id' => $identity->id, 'state' => 'usable', 'provenance_revision' => 1]);
            }
        }
        $reasons = array_values(array_unique($reasons));
        sort($reasons);
        $fields = ['workspace_id' => $workspaceId, 'identity_id' => $identity->id, 'input_hash' => $facts['hash'], 'state' => $reasons ? 'needs_review' : 'ready', 'reason_codes' => json_encode($reasons, JSON_THROW_ON_ERROR)];
        if (! $observation || (int) $observation->identity_id !== (int) $identity->id || $observation->input_hash !== $facts['hash'] || $observation->reason_codes !== $fields['reason_codes'] || $observation->state !== $fields['state']) {
            DB::table('entitlement_client_observations')->updateOrInsert(['project_client_id' => $c->id], $fields + ['revision' => ($observation?->revision ?? 0) + 1, 'observed_at' => now('UTC')]);
        }

        return $new;
    }

    private function alias(int $workspaceId, string $kind, string $issuer, string $value): ?object
    {
        return DB::table('entitlement_client_aliases')->where('workspace_id', $workspaceId)->where('alias_kind', $kind)->where('issuer_hash', $this->hash('issuer', $issuer))->where('alias_hash', $this->hash($kind, $value))->first();
    }
}
