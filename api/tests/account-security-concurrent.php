<?php

use App\Exceptions\AccountSecurityFailure;
use App\Http\Controllers\AccountSecurityController;
use App\Models\User;
use App\Services\AccountSecurity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'projectbrief_m7_security_qa') {
    throw new RuntimeException('Dedicated disposable MariaDB required.');
}
$kind = $argv[2] ?? '';
$session = new Store('m7-race', new ArraySessionHandler(120));
$session->setId('m7'.str_repeat('a', 38));
$session->start();
$request = Request::create('/api/security/passkeys/1', 'DELETE');
$request->setLaravelSession($session);
$security = app(AccountSecurity::class);
if ($argv[1] === 'prepare') {
    $user = User::factory()->owner()->create();
    if ($kind === 'delete') {
        $user->passkeys()->create(['name' => 'Second', 'credential_id' => bin2hex(random_bytes(32)), 'credential' => []]);
    }
    $code = app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
    $challenge = $security->challenge($request, $kind === 'challenge' ? 'passkey_register' : 'race', '{}', $user);
    echo json_encode(['id' => $user->id, 'keys' => $user->passkeys()->pluck('id'), 'code' => $code, 'challenge' => $challenge]);
} elseif ($argv[1] === 'worker') {
    $user = User::findOrFail(getenv('M7_RACE_USER'));
    $request->setUserResolver(fn () => $user);
    $security->confirm($request, $user, 'mfa');
    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN);
    try {
        if ($kind === 'delete') {
            $response = app(AccountSecurityController::class)->deletePasskey($request, (int) $argv[3]);
            $result = $response->getStatusCode();
        } elseif ($kind === 'challenge') {
            $security->consume($request, 'passkey_register', getenv('M7_RACE_CHALLENGE'));
            $result = 200;
        } else {
            $result = DB::transaction(function () use ($security, $kind): int {
                $locked = User::whereKey(getenv('M7_RACE_USER'))->lockForUpdate()->firstOrFail();

                return $security->verifyFactor($locked, $kind === 'totp' ? ['code' => getenv('M7_RACE_CODE')] : ['recovery_code' => 'test-recovery-code']) ? 200 : 422;
            }, 5);
        }
    } catch (ModelNotFoundException) {
        $result = 404;
    } catch (HttpExceptionInterface $error) {
        $result = $error->getStatusCode();
    } catch (ValidationException) {
        $result = 422;
    } catch (AccountSecurityFailure $error) {
        $result = $error->status;
    }
    echo json_encode(['status' => $result]);
} elseif ($argv[1] === 'inspect') {
    $user = User::findOrFail(getenv('M7_RACE_USER'));
    echo json_encode(['passkeys' => $user->passkeys()->count(), 'recoveryCodes' => count($user->recoveryCodes())]);
}
