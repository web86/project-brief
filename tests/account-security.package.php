<?php

use App\Http\Controllers\AccountSecurityController;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\OwnerProvisioning;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WebAuthnDevice;
use Webauthn\Credential;

/** Signed enrollment against extracted no-dev vendor; never runs against an installed site. */
$application = $argv[1] ?? '';
$database = getenv('DB_DATABASE') ?: '';
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || ! str_contains($database, 'm7-passkey-package-') || ! is_file($application.'/vendor/autoload.php')) {
    throw new RuntimeException('Disposable extracted package and SQLite required.');
}
require $application.'/vendor/autoload.php';
require __DIR__.'/../api/tests/Support/WebAuthnDevice.php';
$app = require $application.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Refusing cached or non-disposable database configuration.');
}
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
$owner = app(OwnerProvisioning::class)->create(['name' => 'Package Security', 'email' => 'package-security@example.test', 'password' => 'Disposable-package-password!']);
$session = app('session.store');
$session->start();
$security = app(AccountSecurity::class);
$controller = app(AccountSecurityController::class);
function packageRequest(string $path, array $input = []): Request
{
    global $owner, $session;
    $request = Request::create($path, 'POST', $input);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $owner->fresh());
    app()->instance('request', $request);

    return $request;
}
function packageCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$request = packageRequest('/api/security/status');
$security->complete($request, $owner, 'password');
$controller->setupTotp(packageRequest('/api/security/totp/setup'), app(TwoFactorAuthenticationProvider::class));
$secret = Fortify::currentEncrypter()->decrypt($owner->fresh()->pending_two_factor_secret);
$code = app(Google2FA::class)->getCurrentOtp($secret);
$codes = $controller->confirmTotp(packageRequest('/api/security/totp/confirm', ['code' => $code]))->getData(true)['codes'];
packageCheck(count($codes) === 8, 'Recovery code setup failed');
$controller->acknowledgeRecovery(packageRequest('/api/security/recovery-codes/acknowledge'));
$state = $controller->show(packageRequest('/api/security/status'))->getData(true);
packageCheck($state['state'] === 'authenticated' && ! $state['fresh'] && count($state['passkeys']) === 0, 'TOTP owner must be admitted without Passkey; enrollment must not issue sensitive proof');
$controller->confirm(packageRequest('/api/security/confirm', ['recovery_code' => $codes[7]]));
$step = $owner->fresh()->last_totp_step;
$options = $controller->options(packageRequest('/api/security/passkey/register/options'), 'register')->getData(true)['options'];
packageCheck($options['rp']['id'] === 'brief.web86.site', 'Wrong packaged RP ID');
packageCheck($options['authenticatorSelection']['userVerification'] === 'required', 'UV must remain required');
$device = new WebAuthnDevice;
$credential = $device->registration($options, 'https://brief.web86.site');
try {
    $result = $controller->passkey(packageRequest('/api/security/passkey/register', ['name' => 'Package device', 'credential' => $credential]), 'register')->getData(true);
} catch (Throwable $exception) {
    echo json_encode(['passed' => false, 'php' => PHP_VERSION, 'failureClass' => $exception::class, 'status' => $exception instanceof ValidationException ? $exception->status : 500, 'hasCodeValidationError' => $exception instanceof ValidationException && isset($exception->errors()['code']), 'runtimeBasePresent' => class_exists(Credential::class)], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
packageCheck($result['state'] === 'authenticated' && $result['fresh'] === false, 'Packaged enrollment did not finish');
packageCheck($owner->passkeys()->count() === 1 && $owner->fresh()->last_totp_step === $step, 'Stored credential / TOTP replay counter mismatch');
$reused = DB::transaction(function () use ($owner, $security, $code): bool {
    $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();

    return $security->verifyFactor($locked, ['code' => $code]);
});
packageCheck(! $reused, 'Enrollment TOTP must not be reusable');
echo json_encode(['passed' => true, 'php' => PHP_VERSION, 'origin' => 'https://brief.web86.site', 'rpId' => $options['rp']['id'], 'productionVendor' => ! class_exists(TestCase::class), 'flow' => 'TOTP -> saved recovery -> full access without Passkey -> fresh recovery proof -> signed UV registration -> proof cleared; TOTP replay rejected'], JSON_THROW_ON_ERROR).PHP_EOL;
