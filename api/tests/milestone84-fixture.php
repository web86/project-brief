<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! ((config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')) || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'projectbrief_m80_qa'))) {
    throw new RuntimeException('Disposable QA only');
}
$user = User::where('email', $argv[1])->firstOrFail();
if (($argv[2] ?? '') === 'none') {
    $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'recovery_codes_acknowledged_at' => null])->save();
}
$token = Password::broker('users')->createToken($user);
if (($argv[2] ?? '') === 'expired') {
    DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subHours(2)]);
}
$codes = $user->hasEnabledTwoFactorAuthentication() ? array_values($user->recoveryCodes()) : [];
echo json_encode(['token' => $token, 'email' => $user->email, 'recovery' => $codes[count($codes) - 1] ?? null, 'code' => $user->hasEnabledTwoFactorAuthentication() ? app(Google2FA::class)->oathTotp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), (int) floor(now()->timestamp / 30)) : null]);
