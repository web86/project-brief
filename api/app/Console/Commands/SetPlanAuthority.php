<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountProfile;
use App\Services\AccountSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetPlanAuthority extends Command
{
    protected $signature = 'platform:plan-authority {user : Existing user ID} {--transfer} {--previous-user=} {--expected-revision=} {--reason=initial_setup}';

    protected $description = 'Operator-only interactive initial assignment or independently verified transfer of primary PlanAuthority';

    public function handle(): int
    {
        $id = (string) $this->argument('user');
        $user = ctype_digit($id) ? User::find($id) : null;
        $authority = DB::table('platform_plan_authority')->where('id', 1)->first();
        $transfer = (bool) $this->option('transfer');
        $expected = $authority?->revision ?? 0;
        $previous = $authority?->primary_user_id;
        $reason = (string) $this->option('reason');
        if (! $user || ! $this->eligible($user) || ! preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $reason) || ! $this->input->isInteractive() || ($previous !== null && ! $transfer) || ($transfer && ((string) $this->option('previous-user') !== (string) $previous || (string) $this->option('expected-revision') !== (string) $expected || $previous === null || (int) $previous === $user->id || $reason === 'initial_setup'))) {
            $this->error('Interactive verified operator, eligible existing platform admin and exact transfer revision are required.');

            return self::FAILURE;
        }
        $phrase = $transfer ? 'TRANSFER PLAN AUTHORITY '.$previous.' TO '.$user->id.' REVISION '.$expected : 'ASSIGN PLAN AUTHORITY '.$user->id.' REVISION '.$expected;
        if ($this->ask('After independent operator verification, type: '.$phrase) !== $phrase) {
            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($user, $expected, $previous, $reason, $transfer): void {
                DB::table('platform_plan_authority')->insertOrIgnore(['id' => 1, 'revision' => 0]);
                $current = DB::table('platform_plan_authority')->where('id', 1)->lockForUpdate()->first();
                abort_unless((int) $current->revision === (int) $expected && $current->primary_user_id === $previous, 409);
                $users = User::whereIn('id', array_filter([$user->id, $previous]))->orderBy('id')->lockForUpdate()->get();
                abort_unless($this->eligible($users->firstWhere('id', $user->id)), 403);
                $uuid = (string) Str::uuid();
                $eventId = DB::table('plan_events')->insertGetId(['uuid' => $uuid, 'aggregate_type' => 'plan_authority', 'aggregate_uuid' => $uuid, 'actor_kind' => 'operator', 'action' => $transfer ? 'authority.transferred' : 'authority.assigned', 'result' => 'applied', 'before_revision' => $expected, 'after_revision' => $expected + 1, 'reason_code' => $reason, 'diff_json' => json_encode(['previous_user_id' => $previous, 'primary_user_id' => $user->id]), 'created_at' => now('UTC')]);
                DB::table('platform_plan_authority')->where('id', 1)->update(['primary_user_id' => $user->id, 'revision' => $expected + 1, 'assigned_at' => now('UTC'), 'operator_event_id' => $eventId]);
                foreach ($users as $account) {
                    app(AccountProfile::class)->revokeSessions($account);
                    DB::table('account_security_challenges')->where('user_id', $account->id)->delete();
                }
            }, 3);
        } catch (\Throwable $e) {
            $this->error('Authority assignment failed; no changes committed.');

            return self::FAILURE;
        }
        $this->info('PlanAuthority updated and affected sessions revoked. Sign in again with MFA.');

        return self::SUCCESS;
    }

    private function eligible(?User $user): bool
    {
        return $user !== null && $user->platform_admin && $user->email_verified_at !== null && app(AccountSecurity::class)->enrolled($user) && $user->security_recovery_expires_at === null;
    }
}
