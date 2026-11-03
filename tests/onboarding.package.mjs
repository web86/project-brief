// Exercise onboarding with the production vendor and migrations from the actual extracted ZIP.
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { cp, mkdtemp, writeFile, rm, access } from 'node:fs/promises'
import path from 'node:path'
import { tmpdir } from 'node:os'
const packageRoot = path.resolve(process.argv[2])
const temporary = await mkdtemp(path.join(tmpdir(), 'dev2done-onboarding-package-'))
try {
  const application = path.join(temporary, 'application')
  const database = path.join(temporary, 'qa.sqlite')
  await cp(path.join(packageRoot, 'private/project-brief-app'), application, { recursive: true })
  await writeFile(database, '')
  await assert.rejects(access(path.join(application, 'tests/Support/TrialActivationFixture.php')))
  const code = String.raw`
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function verify(bool $condition): void { if (!$condition) throw new RuntimeException('Packaged onboarding contract failed'); }
verify(app()->environment('testing') && str_contains(Illuminate\Support\Facades\DB::getDatabaseName(),'dev2done-onboarding-package-'));
verify(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true])===0);
verify(class_exists(Jose\Component\Signature\JWSVerifier::class));
verify(!class_exists('Tests\\Support\\TrialActivationFixture'));
verify(!class_exists('Faker\\Factory'));
$legacy = App\Models\User::forceCreate(['name'=>'Synthetic legacy', 'email'=>'legacy-package@example.test', 'password'=>Illuminate\Support\Facades\Hash::make('Synthetic-password-only!')]);
verify($legacy->fresh()->password_login_enabled && !$legacy->email_verified_at);
verify(Illuminate\Support\Facades\DB::table('account_onboardings')->count()===0);
config(['onboarding.registration_enabled'=>true,'onboarding.trial_activation_requested'=>true,'onboarding.subject_retention_days'=>365]);
$app->instance('env','production');
$readiness=app(App\Services\RegistrationReadiness::class);
verify(!$readiness->registrationEnabled() && !$readiness->activationEnabled());
try { app(App\Services\AuthOnboarding::class)->register(['email'=>'blocked@example.test','password'=>'Synthetic-password-only!']); throw new LogicException('Production registration opened'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { verify($e->getStatusCode()===403); }
verify(App\Models\User::count()===1);
$app->instance('env','testing');
$mail = new class extends App\Services\AccountMail {
 public ?string $action=null;
 public function send(string $email,string $heading,string $message,?string $url=null,string $locale='en',?array $branding=null,?string $actionLabel=null): bool { $this->action=$url; return true; }
};
$app->instance(App\Services\AccountMail::class,$mail);
$onboarding=app(App\Services\AuthOnboarding::class);
$onboarding->register(['email'=>'new-package@example.test','password'=>'Synthetic-password-only!','locale'=>'tr']);
verify(Illuminate\Support\Facades\DB::table('workspaces')->count()===0);
verify($mail->action!==null);
parse_str(parse_url($mail->action,PHP_URL_FRAGMENT),$fragment);
$user=App\Models\User::where('email','new-package@example.test')->firstOrFail();
verify(!$user->email_verified_at && !$user->platform_admin);
$first=$onboarding->verify($user,$fragment['token']);
$second=$onboarding->verify($user,$fragment['token']);
verify(!$first['replayed'] && $second['replayed']);
verify(Illuminate\Support\Facades\DB::table('workspace_members')->where('role','owner')->count()===1);
$claim=Illuminate\Support\Facades\DB::table('owner_trial_claims')->first();
verify($claim->state==='pending' && $claim->starts_at===null && $claim->ends_at===null && $claim->period_id===null);
verify(Illuminate\Support\Facades\DB::table('owner_trial_claim_subjects')->count()===1);
verify(Illuminate\Support\Facades\DB::table('workspace_subscriptions')->count()===0);
verify(Illuminate\Support\Facades\DB::table('subscription_periods')->count()===0);
verify(Illuminate\Support\Facades\DB::table('integration_tokens')->count()===0);
verify(Illuminate\Support\Facades\DB::table('platform_plan_authority')->count()===0);
foreach (['google','yandex','github'] as $provider) {
 verify(!$readiness->provider($provider));
 $route=app('router')->getRoutes()->match(Illuminate\Http\Request::create('/auth/'.$provider.'/callback'));
 verify(str_contains($route->getActionName(),'SocialAuthController'));
 config(['onboarding.providers.'.$provider=>['client_id'=>'synthetic','client_secret'=>'synthetic','callback'=>'https://evil.example/auth/'.$provider.'/callback']]);
 verify(!$readiness->provider($provider));
}
verify(Illuminate\Support\Facades\Artisan::call('onboarding:prune-attempts',['--dry-run'=>true])===0);
echo "PASS: production vendor onboarding, legacy defaults, hard production gates, atomic proof/provisioning, no Trial clock or fixture runtime\n";
`
  const result = spawnSync(process.env.PHP_BIN || 'php', ['-r', code, application], {
    cwd: application,
    encoding: 'utf8',
    env: {
      ...process.env,
      APP_ENV: 'testing',
      APP_DEBUG: 'false',
      APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
      APP_URL: 'http://localhost',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: database,
      DB_URL: '',
      CACHE_STORE: 'array',
      SESSION_DRIVER: 'array',
      LOG_CHANNEL: 'null',
      MAIL_MAILER: 'array',
      BCRYPT_ROUNDS: '4',
    },
  })
  assert.equal(result.status, 0, result.stdout + result.stderr)
  console.log(result.stdout.trim())
} finally {
  await rm(temporary, { recursive: true, force: true })
}
