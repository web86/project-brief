<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use App\Services\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetPlatformAdmin extends Command
{
    protected $signature = 'platform:admin {user : Existing numeric user ID} {--revoke : Revoke the global privilege}';

    protected $description = 'Operator-only interactive grant/revoke; requires verified server access and enrolled MFA';

    public function handle(): int
    {
        $id = (string) $this->argument('user');
        $user = ctype_digit($id) ? User::find($id) : null;
        $grant = ! $this->option('revoke');
        if (! $user || ($grant && ! app(AccountSecurity::class)->enrolled($user))) {
            $this->error('Existing user with confirmed MFA and saved recovery codes required for a grant.');

            return self::FAILURE;
        }
        $phrase = ($grant ? 'GRANT' : 'REVOKE').' PLATFORM ADMIN '.$user->id;
        if (! $this->input->isInteractive() || $this->ask('After independent identity verification, type: '.$phrase) !== $phrase) {
            $this->error('Cancelled. Interactive operator confirmation is mandatory.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $grant): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($grant && ! app(AccountSecurity::class)->enrolled($locked)) {
                throw new \RuntimeException('MFA enrollment changed.');
            }
            $locked->forceFill(['platform_admin' => $grant])->save();
            app(AccountProfile::class)->revokeSessions($locked);
            DB::table('account_security_challenges')->where('user_id', $locked->id)->delete();
            app(PlatformAudit::class)->record(null, $grant ? 'privilege_granted' : 'privilege_revoked', 'user', (string) $locked->id, 'operator');
        });
        $this->info('Global privilege updated; all account sessions and proofs revoked. Sign in again.');

        return self::SUCCESS;
    }
}
