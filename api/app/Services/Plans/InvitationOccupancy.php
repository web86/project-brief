<?php

namespace App\Services\Plans;

use App\Services\DeveloperInvitations;
use App\Services\IntegrationWrites;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class InvitationOccupancy
{
    public function identity(string $email, ?int $userId = null): string
    {
        return hash_hmac('sha256', $userId === null ? 'invite-email|'.DeveloperInvitations::normalizeEmail($email) : 'human|'.$userId, (string) config('app.key'));
    }

    public function invitationDelta(int $workspaceId, string $email): ?int
    {
        $email = DeveloperInvitations::normalizeEmail($email);
        $users = DB::table('users')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->sharedLock()->get(['id']);
        if ($users->count() > 1) {
            return null;
        }
        if ($users->count() === 1 && DB::table('workspace_members')->where('workspace_id', $workspaceId)->where('user_id', $users->first()->id)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->sharedLock()->first()) {
            return 0;
        }
        $invites = DB::table('developer_invitations')->where('workspace_id', $workspaceId)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))->orderBy('id')->limit(251)->sharedLock()->get();
        if ($invites->count() > 250) {
            return null;
        }
        foreach ($this->managerValidity($invites, $workspaceId, true) as $valid) {
            if ($valid === true) {
                return 0;
            } if ($valid === null) {
                return null;
            }
        }

        return 1;
    }

    /** Shadow persistence must not reject an otherwise authorized invitation operation. */
    public function observe(int $invitationId): void
    {
        $this->shadow(fn () => $this->sync($invitationId));
    }

    public function observeRevocations(int $workspaceId): void
    {
        $this->shadow(fn () => DB::table('entitlement_capacity_reservations')->where('workspace_id', $workspaceId)->where('state', 'held')->whereIn('developer_invitation_id', DB::table('developer_invitations')->where('workspace_id', $workspaceId)->whereNotNull('revoked_at')->select('id'))->update(['state' => 'released', 'updated_at' => now('UTC')]));
    }

    private function shadow(callable $operation): void
    {
        try {
            DB::transaction($operation);
        } catch (\Throwable) {
            // Never log SQL, email or the exception payload from an accounting adapter.
            Log::warning('Invitation shadow accounting requires reconciliation.', ['reason_code' => 'invitation_reservation_refresh_required']);
        }
    }

    /** Caller holds the workspace mutex, before invitation/member locks. Never checks a tariff. */
    public function sync(int $invitationId): void
    {
        $i = DB::table('developer_invitations')->find($invitationId);
        if (! $i || ! DB::table('entitlement_definitions')->where('key', 'developers.max')->exists()) {
            return;
        }
        $known = DB::table('users')->whereRaw('LOWER(TRIM(email)) = ?', [DeveloperInvitations::normalizeEmail($i->email)])->value('id');
        $managerValid = $this->managerValidity(collect([$i]), $i->workspace_id)[$i->id];
        $state = $i->accepted_at ? 'consumed' : ($i->revoked_at || $managerValid === false || CarbonImmutable::parse($i->expires_at, 'UTC') <= CarbonImmutable::now('UTC') ? 'released' : 'held');
        $previous = DB::table('entitlement_capacity_reservations')->where('developer_invitation_id', $i->id)->where('key', 'developers.max')->first();
        if ($previous?->state === 'suspended' && $state === 'held') {
            $state = 'suspended';
        }
        $fields = ['identity_hash' => $this->identity($i->email, $known), 'expires_at' => $i->expires_at, 'state' => $state];
        if ($previous && $previous->identity_hash === $fields['identity_hash'] && $previous->expires_at === $fields['expires_at'] && $previous->state === $state) {
            return;
        }
        DB::table('entitlement_capacity_reservations')->updateOrInsert(['developer_invitation_id' => $i->id, 'key' => 'developers.max', 'scope_kind' => 'workspace', 'scope_id' => 0], $fields + ['workspace_id' => $i->workspace_id, 'updated_at' => now('UTC'), ...($previous ? [] : ['created_at' => now('UTC')])]);
    }

    public function reconcile(int $workspaceId, int $afterId = 0, ?int $cutoffId = null): array
    {
        return DB::transaction(function () use ($workspaceId, $afterId, $cutoffId): array {
            IntegrationWrites::lockWorkspace($workspaceId);
            $cutoff = $cutoffId ?? (int) DB::table('developer_invitations')->where('workspace_id', $workspaceId)->max('id');
            $rows = DB::table('developer_invitations')->where('workspace_id', $workspaceId)->where('id', '>', $afterId)->where('id', '<=', $cutoff)->orderBy('id')->limit(200)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->sync($row->id);
            }

            return ['last_id' => $rows->last()?->id ?? $cutoff, 'done' => $rows->count() < 200 || ($rows->last()?->id ?? 0) >= $cutoff];
        }, 3);
    }

    /** Current invitation facts, not stale reservation hashes, define the union after email edits. */
    public function measure(int $workspaceId, CarbonImmutable $at, bool $current = false): array
    {
        $members = DB::table('workspace_members')->when($current, fn ($q) => $q->lockForUpdate())->where('workspace_id', $workspaceId)->where('status', 'active')->whereIn('role', ['owner', 'admin']);
        $humans = [];
        foreach ((clone $members)->orderBy('id')->limit(20001)->get(['user_id']) as $member) {
            $humans[$this->identity('', $member->user_id)] = true;
            if (count($humans) > 20000) {
                return ['state' => 'incomplete', 'count' => null, 'reason_codes' => ['occupancy_scan_limit']];
            }
        }
        $occupied = $humans;
        $count = 0;
        $refresh = false;
        DB::table('developer_invitations')->where('workspace_id', $workspaceId)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', $at->format('Y-m-d H:i:s.u'))->when($current, fn ($q) => $q->lockForUpdate())->orderBy('id')->chunkById(200, function ($rows) use ($current, $workspaceId, &$occupied, &$count, &$refresh): bool {
            $validity = $this->managerValidity($rows, $workspaceId, $current);
            $emails = $rows->map(fn ($i) => DeveloperInvitations::normalizeEmail($i->email))->unique()->all();
            $users = DB::table('users')->whereIn(DB::raw('LOWER(TRIM(email))'), $emails)->when($current, fn ($q) => $q->sharedLock())->orderBy('id')->get(['id', 'email'])->groupBy(fn ($u) => DeveloperInvitations::normalizeEmail($u->email));
            $holds = DB::table('entitlement_capacity_reservations')->where('key', 'developers.max')->whereIn('developer_invitation_id', $rows->pluck('id'))->when($current, fn ($q) => $q->lockForUpdate())->get()->keyBy('developer_invitation_id');
            foreach ($rows as $i) {
                if ($validity[$i->id] === false) {
                    continue;
                }
                if ($validity[$i->id] === null) {
                    $count = 20001;

                    return false;
                }
                if (++$count > 20000) {
                    return false;
                }
                $known = $users->get(DeveloperInvitations::normalizeEmail($i->email));
                if ($known && $known->count() > 1) {
                    $count = 20001;

                    return false;
                }
                $identity = $this->identity($i->email, $known?->first()?->id);
                $hold = $holds->get($i->id);
                if ($hold?->state === 'suspended') {
                    $count = 20001;

                    return false;
                }
                $refresh = $refresh || ! $hold || $hold->state !== 'held' || $hold->identity_hash !== $identity || $hold->expires_at !== $i->expires_at;
                $occupied[$identity] = true;
            }

            return true;
        });
        $owners = (clone $members)->where('role', 'owner')->get(['user_id'])->unique('user_id')->count();
        $reasons = $owners === 1 ? [] : ['ownership_review_required'];
        if ($count > 20000) {
            $reasons[] = 'invitation_accounting_review_required';
        }

        ksort($occupied);

        return ['identity_fingerprint' => hash('sha256', json_encode(array_keys($occupied))), 'state' => $reasons ? 'incomplete' : 'ready', 'count' => $count > 20000 ? null : count($occupied), 'human_count' => count($humans), 'invitation_slots' => $count > 20000 ? null : count($occupied) - count($humans), 'observed_human_members' => count($humans), 'reason_codes' => $reasons, 'reservation_refresh_required' => $refresh];
    }

    public function managerValidity(Collection $rows, int $workspaceId, bool $current = false): array
    {
        $scoped = $rows->filter(fn ($i) => $i->project_scoped);
        $managers = DB::table('workspace_members')->when($current, fn ($q) => $q->sharedLock())->where('workspace_id', $workspaceId)->whereIn('user_id', $scoped->pluck('invited_by'))->where('status', 'active')->whereIn('role', ['owner', 'admin'])->get()->keyBy('user_id');
        $projects = [];
        $parsed = [];
        foreach ($scoped as $i) {
            try {
                $ids = json_decode($i->project_ids, true, 16, JSON_THROW_ON_ERROR);
                $parsed[$i->id] = is_array($ids) && array_is_list($ids) ? $ids : null;
                $projects = array_merge($projects, $parsed[$i->id] ?? []);
            } catch (\JsonException) {
                $parsed[$i->id] = null;
            }
        }
        $existing = DB::table('projects')->when($current, fn ($q) => $q->sharedLock())->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('id', $projects)->pluck('id')->all();
        $assignments = DB::table('project_members')->when($current, fn ($q) => $q->sharedLock())->where('workspace_id', $workspaceId)->whereIn('workspace_member_id', $managers->pluck('id'))->whereIn('project_id', $existing)->get()->groupBy('workspace_member_id');
        $result = [];
        foreach ($rows as $i) {
            if (! $i->project_scoped) {
                $result[$i->id] = true;

                continue;
            }
            $ids = $parsed[$i->id];
            $manager = $managers->get($i->invited_by);
            if ($ids === null) {
                $result[$i->id] = null;
            } elseif (! $manager || ($manager->role !== 'owner' && ! $manager->is_project_superadmin) || ! $ids || count(array_unique($ids)) !== count($ids) || array_diff($ids, $existing)) {
                $result[$i->id] = false;
            } else {
                $result[$i->id] = $manager->role === 'owner' || ! array_diff($ids, $assignments->get($manager->id, collect())->pluck('project_id')->all());
            }
        }

        return $result;
    }
}
