// Validate plan setup/domain using only production vendor from the actual extracted ZIP.
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { cp, mkdtemp, writeFile, rm, readdir } from 'node:fs/promises'
import path from 'node:path'
import { tmpdir } from 'node:os'
const packageRoot = path.resolve(process.argv[2])
const temporary = await mkdtemp(path.join(tmpdir(), 'projectbrief-plans-package-'))
try {
  const application = path.join(temporary, 'application')
  const database = path.join(temporary, 'qa.sqlite')
  await cp(path.join(packageRoot, 'private/project-brief-app'), application, { recursive: true })
  await writeFile(database, '')
  assert.deepEqual((await readdir(path.join(application, 'database/seeders'))).sort(), [
    'PlansFoundationSeeder.php',
  ])
  assert.deepEqual(await readdir(path.join(application, 'database/factories')), [])
  assert.ok(
    (await readdir(path.join(packageRoot, 'public/assets'))).some((name) =>
      /^PlatformPlansView-.*\.js$/.test(name),
    ),
  )
  const code = String.raw`
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function verify(bool $valid): void { if (! $valid) { throw new RuntimeException('Packaged plan contract failed.'); } }
verify(app()->environment('testing'));
verify(str_contains(Illuminate\Support\Facades\DB::getDatabaseName(), 'projectbrief-plans-package-'));
verify(Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true, '--no-interaction'=>true]) === 0);
foreach ([1,2] as $attempt) {
    verify(Illuminate\Support\Facades\Artisan::call('db:seed', ['--class'=>'PlansFoundationSeeder', '--no-interaction'=>true]) === 0);
}
verify(App\Models\Plans\Plan::count() === 3);
verify(App\Models\Plans\PlanVersion::where('state','draft')->count() === 3);
verify(Illuminate\Support\Facades\DB::table('plan_settings')->where('id',1)->value('current_policy_version_id') === null);
$user = App\Models\User::forceCreate(['name'=>'Synthetic package operator','email'=>'package@example.test','email_verified_at'=>now(),'password'=>Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(20))),'role'=>'user','platform_admin'=>true]);
verify(Illuminate\Support\Facades\Artisan::call('platform:plan-authority', ['user'=>$user->id,'--no-interaction'=>true]) !== 0);
verify(Illuminate\Support\Facades\DB::table('platform_plan_authority')->count() === 0);
Illuminate\Support\Facades\DB::table('platform_plan_authority')->insert(['id'=>1,'primary_user_id'=>$user->id,'revision'=>1]);
$catalog = app(App\Services\Plans\PlanCatalog::class);
$basic = App\Models\Plans\PlanVersion::whereHas('plan',fn($q)=>$q->where('code','basic'))->firstOrFail();
$configuration = $catalog->configuration($basic);
$draft = $catalog->create($user,'package-fourth',$configuration,'package-create');
$management = app(App\Services\Plans\PlanManagement::class);
$preview = $management->preview($user,$draft,1,'package-preview');
$published = $management->publish($user,$draft,1,$preview->uuid,$preview->target_hash,'package-publish');
$replay = $management->publish($user,$draft,1,$preview->uuid,$preview->target_hash,'package-publish');
verify($published->id === $replay->id && $published->state === 'published');
$blocked = false;
try { Illuminate\Support\Facades\DB::table('plan_versions')->where('id',$published->id)->update(['revision'=>99]); }
catch (Illuminate\Database\QueryException $e) { $blocked = true; }
verify($blocked);
verify(Illuminate\Support\Facades\Artisan::call('db:seed', ['--class'=>'PlansFoundationSeeder', '--no-interaction'=>true]) === 0);
verify(App\Models\Plans\Plan::count() === 4);
verify(Illuminate\Support\Facades\DB::table('workspace_subscriptions')->count() === 0);
verify(Illuminate\Support\Facades\DB::table('owner_trial_claims')->count() === 0);
verify(! class_exists('Faker\\Factory'));
$route = app('router')->getRoutes()->match(Illuminate\Http\Request::create('/api/platform/plans'));
verify(str_contains($route->getActionName(),'PlatformPlansController'));
echo json_encode(['passed'=>true,'draftSeed'=>'idempotent','publication'=>'immutable','subscriptions'=>0,'trialClaims'=>0,'productionVendor'=>true],JSON_THROW_ON_ERROR);
`
  const inherited = Object.fromEntries(
    Object.entries(process.env).filter(
      ([key]) =>
        !/^(APP_|DB_|DATABASE_|SESSION_|CACHE_|AI_|GROQ_|ZVENO_|TELEGRAM_|LOG_|MAIL_)/.test(key),
    ),
  )
  const result = spawnSync(
    process.env.PHP_BIN || 'php',
    ['-d', 'memory_limit=512M', '-r', code, application],
    {
      cwd: application,
      encoding: 'utf8',
      timeout: 60000,
      env: {
        ...inherited,
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: database,
        DB_URL: '',
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'array',
        LOG_CHANNEL: 'null',
        MAIL_MAILER: 'array',
      },
    },
  )
  assert.equal(result.status, 0, 'Packaged plan domain failed: ' + result.stderr)
  const data = JSON.parse(result.stdout)
  assert.equal(data.passed, true)
  console.log('PASS: Packaged plan setup and immutable publication ' + JSON.stringify(data))
} finally {
  await rm(temporary, { recursive: true, force: true })
}
