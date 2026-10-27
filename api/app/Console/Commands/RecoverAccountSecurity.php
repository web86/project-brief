<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class RecoverAccountSecurity extends Command
{
    protected $signature = 'account:recover-security {email : Owner account email}';

    protected $description = 'Operator-only recovery after independent identity verification; revokes all account sessions and factors';

    public function handle(): int
    {
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($this->argument('email')))])->first();
        if (! $user || ! $user->isWorkspaceOwner()) {
            $this->error('Active workspace owner not found.');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive() || $this->ask('After independent identity verification, type the owner email to revoke ALL factors and sessions') !== $user->email) {
            $this->error('Recovery cancelled.');

            return self::FAILURE;
        }
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($user, $token): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->passkeys()->delete();
            $locked->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'pending_two_factor_secret' => null, 'last_totp_step' => null, 'recovery_codes_acknowledged_at' => null, 'security_recovery_token_hash' => hash('sha256', $token), 'security_recovery_expires_at' => now()->addMinutes(30)])->save();
            app(AccountProfile::class)->revokeSessions($locked);
            Password::broker('users')->deleteToken($locked);
            DB::table('account_security_challenges')->where('user_id', $locked->id)->delete();
            app(AccountSecurity::class)->audit($locked, 'operator_recovery_started');
        });
        $this->warn('Deliver this single-use token through the independently verified channel. It expires in 30 minutes. The password is also required.');
        $this->line($token);
        $this->info('Login opens restricted setup only. If the browser session is lost, repeat operator recovery.');

        return self::SUCCESS;
    }
}
