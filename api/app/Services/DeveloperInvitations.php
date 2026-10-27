<?php

namespace App\Services;

use App\Models\DeveloperInvitation;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeveloperInvitations
{
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function emailsMatch(string $accountEmail, string $invitedEmail): bool
    {
        return self::normalizeEmail($accountEmail) === self::normalizeEmail($invitedEmail);
    }

    public function owner(Request $request): WorkspaceMember
    {
        $member = app(CurrentWorkspace::class)->resolve($request, DB::transactionLevel() > 0);
        abort_unless($member->role === 'owner', 403);
        if (! $request->isMethodSafe()) {
            app(AccountSecurity::class)->requireFresh($request);
        }

        return $member;
    }

    public function projects(WorkspaceMember $owner, array $uuids): array
    {
        $projects = $owner->workspace->projects()->whereIn('uuid', $uuids)->pluck('id')->all();
        if (count($projects) !== count(array_unique($uuids))) {
            throw ValidationException::withMessages(['projects' => ['Invalid workspace projects.']]);
        }

        return $projects;
    }

    public function deliver(DeveloperInvitation $invitation): bool
    {
        $token = bin2hex(random_bytes(32));
        $invitation->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'revoked_at' => null])->save();

        $recipient = User::whereRaw('LOWER(TRIM(email)) = ?', [self::normalizeEmail($invitation->email)])->first();

        return app(AccountMail::class)->localized($invitation->email, $recipient?->preferred_locale ?? $invitation->locale, 'invitation',
            rtrim(config('app.url'), '/').'/invitation#token='.$token, ['workspace' => $invitation->workspace->name]);
    }

    public function resolve(string $token, bool $lock = false): DeveloperInvitation
    {
        $query = DeveloperInvitation::where('token_hash', hash('sha256', $token));
        if ($lock) {
            $query->lockForUpdate();
        }
        $invitation = $query->first();
        abort_unless($invitation && $invitation->active(), 410, 'Invitation is unavailable.');

        return $invitation;
    }

    public function accept(Request $request, array $data): User
    {
        return DB::transaction(function () use ($request, $data): User {
            $preview = $this->resolve($data['token']);
            Workspace::whereKey($preview->workspace_id)->lockForUpdate()->firstOrFail();
            $invitation = $this->resolve($data['token'], true);
            $user = $request->user();
            if (! $user) {
                abort_if(User::whereRaw('LOWER(TRIM(email)) = ?', [$invitation->email])->exists(), 401, 'Sign in to accept this invitation.');
                validator($data, ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:9', 'max:1024', 'confirmed']])->validate();
                try {
                    $user = User::create(['name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password'], 'preferred_locale' => $invitation->locale]);
                } catch (UniqueConstraintViolationException) {
                    abort(401, 'Sign in to accept this invitation.');
                }
            }
            abort_unless(self::emailsMatch($user->email, $invitation->email), 403, 'Invitation email must match your account.');
            // The emailed single-use token proves the mailbox, while existing accounts also require authentication.
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            $member = WorkspaceMember::firstOrCreate(['workspace_id' => $invitation->workspace_id, 'user_id' => $user->id], ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);
            if ($member->status !== 'active') {
                $member->can_create_projects = false;
            }
            $member->forceFill(['status' => 'active', 'joined_at' => $member->joined_at ?? now()])->save();
            foreach ($invitation->workspace->projects()->whereIn('id', $invitation->project_ids)->pluck('id') as $projectId) {
                ProjectMember::firstOrCreate(['project_id' => $projectId, 'workspace_member_id' => $member->id], ['workspace_id' => $member->workspace_id]);
            }
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        }, 5);
    }
}
