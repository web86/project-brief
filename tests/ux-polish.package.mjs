import assert from 'node:assert/strict'
import { cp, mkdtemp, writeFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { randomBytes } from 'node:crypto'
import { spawnSync } from 'node:child_process'
const packageRoot = path.resolve(process.argv[2])
const temporary = await mkdtemp(path.join(tmpdir(), 'projectbrief-ux-package-'))
try {
  const appRoot = path.join(temporary, 'app')
  await cp(path.join(packageRoot, 'private/project-brief-app'), appRoot, { recursive: true })
  const database = path.join(temporary, 'test.sqlite')
  await writeFile(database, '')
  const code = String.raw`
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function verify(bool $value): void { if (!$value) { throw new RuntimeException('Packaged UX/privacy isolation contract failed'); } }
foreach (['DemoController','DemoIdentity','DemoReadOnly','DemoEnvironment','DemoServiceProvider'] as $class) {
    foreach (['Http\\Controllers\\','Http\\Middleware\\','Services\\','Providers\\'] as $namespace) { verify(!class_exists('App\\'.$namespace.$class)); }
}
verify(!is_file($argv[1].'/config/demo.php') && !is_file($argv[1].'/routes/demo.php'));
verify(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true])===0);
verify(Illuminate\Support\Facades\DB::table('platform_plan_authority')->count()===0);
verify(Illuminate\Support\Facades\DB::table('plans')->count()===0);
$workspace=App\Models\Workspace::create(['name'=>'Package studio']);
$user=App\Models\User::create(['name'=>'Package developer','email'=>'package@example.test','password'=>Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(20)))]);
$workspace->members()->create(['user_id'=>$user->id,'role'=>'owner','status'=>'active','joined_at'=>now()]);
$user->forceFill(['two_factor_secret'=>Laravel\Fortify\Fortify::currentEncrypter()->encrypt(app(PragmaRX\Google2FA\Google2FA::class)->generateSecretKey()),'two_factor_confirmed_at'=>now(),'recovery_codes_acknowledged_at'=>now()])->save();
verify(!app(App\Services\AccountSecurity::class)->recommendationsPending($user));
$project=$workspace->projects()->create(['title'=>'Package project','currency'=>'USD','status'=>'active']);
$client=$project->clients()->create(['name'=>'Package client','email'=>'client@example.test','active'=>true]);
$section=$project->briefSections()->create(['name'=>'Delivery','position'=>1]);
$task=$project->tasks()->create(['project_section_id'=>$section->id,'position'=>1,'title'=>'Reviewed task','description'=>'Public brief','status'=>'review','client_approved'=>true,'price'=>99,'developer_notes'=>'PRIVATE_DEVELOPER']);
$agent=App\Models\ExternalAgent::create(['workspace_id'=>$workspace->id,'name'=>'PRIVATE_EXECUTOR','kind'=>'ai','active'=>true]);
$task->comments()->create(['project_id'=>$project->id,'author_type'=>'agent','external_agent_id'=>$agent->id,'team_only'=>false,'text'=>'PRIVATE_LEGACY']);
$task->comments()->create(['project_id'=>$project->id,'author_type'=>'agent','external_agent_id'=>$agent->id,'team_only'=>true,'text'=>'PRIVATE_REPORT']);
$public=$task->comments()->create(['project_id'=>$project->id,'author_type'=>'admin','author_user_id'=>$user->id,'text'=>'Reviewed team result']);
foreach(['agent_assigned','agent_run_requested','agent_run_started','agent_reports','agent_unassigned','agent_run_cancelled'] as $type) { $task->history()->create(['actor_type'=>'admin','event_type'=>$type,'meta'=>['agentName'=>'PRIVATE_EXECUTOR','text'=>'PRIVATE_INTERNAL'],'created_at'=>now()]); }
$task->history()->create(['actor_type'=>'admin','event_type'=>'comment','meta'=>['commentId'=>$public->id,'agentSourceCommentId'=>42,'text'=>'Agent result published'],'created_at'=>now()]);
$agent->delete();
$request=Illuminate\Http\Request::create('/api/client/tasks/'.$task->uuid);
$request->attributes->set('client_project',$project);$request->attributes->set('project_client',$client);
$data=(new App\Http\Resources\TaskResource($task->fresh()))->resolve($request);$json=json_encode($data);
verify(count($data['history'])===1 && count($data['comments'])===1);
foreach(['PRIVATE_','agentName','agentReport','agentKind','agentAssignments','agentSource','developerNotes','estimateHours','"price"'] as $needle) { verify(!str_contains($json,$needle)); }
$admin=Illuminate\Http\Request::create('/api/admin/tasks/'.$task->uuid);$admin->setUserResolver(fn()=>$user);$admin->setLaravelSession(app('session.store'));
$internal=(new App\Http\Resources\TaskResource($task->fresh()))->resolve($admin);
verify(count($internal['history'])===7 && count($internal['comments'])===3);
verify(Illuminate\Support\Facades\DB::table('comments')->where('text','PRIVATE_LEGACY')->value('team_only')==false);
$export=app(App\Services\ProjectExportData::class)->build($project,'all','en',['attachments'=>true],client:true);
verify(!str_contains(json_encode($export),'PRIVATE_'));
foreach(['/demo','/demo/developer','/demo/client','/api/demo/status','/api/demo/enter/developer'] as $path) { $response=$app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create($path));verify($response->getStatusCode()===404); }
echo json_encode(['passed'=>true,'clientHistory'=>1,'developerHistory'=>7,'legacyRowsPreserved'=>true,'anonymousDemoAbsent'=>true,'forgedDemoFlagIgnored'=>true,'automaticPlanSeed'=>false]);
`
  const inherited = Object.fromEntries(
    Object.entries(process.env).filter(
      ([key]) => !/^(APP_|DB_|DATABASE_|SESSION_|CACHE_|AI_|DEMO_|MAIL_|QUEUE_)/.test(key),
    ),
  )
  const result = spawnSync(
    process.env.PHP_BIN || 'php',
    ['-d', 'memory_limit=512M', '-r', code, appRoot],
    {
      cwd: appRoot,
      encoding: 'utf8',
      timeout: 60000,
      env: {
        ...inherited,
        APP_ENV: 'testing',
        APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
        DEMO_ENABLED: 'true',
        DEMO_READ_ONLY: 'false',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: database,
        DB_URL: '',
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'array',
        LOG_CHANNEL: 'null',
        MAIL_MAILER: 'array',
        BCRYPT_ROUNDS: '4',
      },
    },
  )
  assert.equal(result.status, 0, result.stderr)
  assert.equal(JSON.parse(result.stdout).passed, true)
  console.log('PASS: Packaged UX/privacy and absent anonymous demo ' + result.stdout)
} finally {
  await rm(temporary, { recursive: true, force: true })
}
