<?php

namespace Tests\Feature;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\AuthOnboarding;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlatformPolicyCatalog;
use App\Services\SocialAuth;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OnboardingConcurrencyTest extends TestCase
{
    public function test_independent_workers_serialize_registration_callback_linking_provisioning_and_trial_claims(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent workers on exclusive disposable MariaDB.');
        }
        $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->configuration();
        try {
            $this->assertSame(['accepted', 'accepted'], $this->race('register'));
            $this->assertSame(1, User::where('email', 'race-email@example.test')->count());
            $this->assertDatabaseCount('workspace_members', 0);
            $this->assertDatabaseCount('owner_trial_claims', 0);
            $attempt = $this->attempt();
            $outcomes = $this->race('callback', ['sessions' => [$attempt['session'], $attempt['session']], 'states' => [$attempt['state'], $attempt['state']], 'githubId' => 42]);
            sort($outcomes);
            $this->assertSame(['accepted', 'rejected'], $outcomes);
            $this->assertSame(1, User::where('email', 'callback@example.test')->count());
            $this->assertSame(1, DB::table('user_external_identities')->where('provider_key', 'github')->count());
            $this->assertDatabaseCount('subscription_periods', 0);
            $one = User::factory()->owner()->create();
            $two = User::factory()->owner()->create();
            $a = $this->attempt($one);
            $b = $this->attempt($two);
            $outcomes = $this->race('link', ['sessions' => [$a['session'], $b['session']], 'states' => [$a['state'], $b['state']], 'actors' => [$one->id, $two->id], 'proofs' => [$a['proof'], $b['proof']], 'githubId' => 84]);
            sort($outcomes);
            $this->assertSame(['accepted', 'rejected'], $outcomes);
            $this->assertSame(1, DB::table('user_external_identities')->whereIn('user_id', [$one->id, $two->id])->count());
            $this->assertSame(1, DB::table('plan_events')->where('action', 'social.linked')->count());
            $owner = User::factory()->create(['email' => 'trial-race@example.test']);
            app(AuthOnboarding::class)->pending($owner);
            $this->assertSame(['accepted', 'accepted'], $this->race('prepare', ['owner' => $owner->id]));
            $this->assertSame(1, $owner->workspaceMembers()->count());
            $this->assertSame(1, DB::table('owner_trial_claims')->where('owner_user_id', $owner->id)->count());
            $this->assertDatabaseCount('subscription_periods', 0);
            $primary = User::factory()->create(['platform_admin' => true]);
            DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $primary->id, 'revision' => 1]);
            $this->seed(PlansFoundationSeeder::class);
            foreach (['basic', 'studio'] as $code) {
                $v = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', $code))->firstOrFail();
                app(PlanCatalog::class)->publish($primary, $v->id, 1, 'fixture-'.$code);
            }
            $policy = app(PlatformPolicyCatalog::class)->publish($primary, PlatformPolicyVersion::firstOrFail()->id, 1, 'fixture-policy');
            DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
            $this->assertSame(['accepted', 'accepted'], $this->race('activate_fixture', ['owner' => $owner->id]));
            $this->assertDatabaseCount('subscription_periods', 1);
            $claim = DB::table('owner_trial_claims')->where('owner_user_id', $owner->id)->first();
            $this->assertNotNull($claim->period_id);
            $this->assertSame(604800, CarbonImmutable::parse($claim->ends_at, 'UTC')->getTimestamp() - CarbonImmutable::parse($claim->starts_at, 'UTC')->getTimestamp());
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function configuration(): void
    {
        config(['app.url' => 'http://localhost', 'onboarding.registration_enabled' => true, 'onboarding.subject_retention_days' => 365, 'session.driver' => 'array', 'onboarding.providers.github' => ['client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'callback' => 'http://localhost/auth/github/callback']]);
    }

    private function attempt(?User $actor = null): array
    {
        $session = app('session')->driver('array');
        $session->flush();
        $session->setId(bin2hex(random_bytes(20)));
        $session->start();
        $request = Request::create('/api/auth/github/'.($actor ? 'link' : 'start'));
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $actor);
        if ($actor) {
            app(AccountSecurity::class)->confirm($request, $actor, 'mfa');
        }
        $url = app(SocialAuth::class)->start($request, 'github', $actor ? 'link' : 'login');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return ['state' => $query['state'], 'session' => $session->getId(), 'proof' => $session->get('security')];
    }

    private function race(string $mode, array $input = []): array
    {
        $directory = sys_get_temp_dir().'/d2d-onboarding-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/input.json', json_encode($input + ['mode' => $mode]));
        $worker = <<<'WORKER'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || Illuminate\Support\Facades\DB::getDatabaseName() !== 'projectbrief_agent_ux_full_qa') { exit(90); }
$input = json_decode(file_get_contents($argv[2].'/input.json'), true);
$index = (int) $argv[3];
config(['app.url'=>'http://localhost','onboarding.registration_enabled'=>true,'onboarding.subject_retention_days'=>365,'session.driver'=>'array','cache.default'=>'array','onboarding.providers.github'=>['client_id'=>'synthetic-client','client_secret'=>'synthetic-secret','callback'=>'http://localhost/auth/github/callback']]);
$session = $app->make('session')->driver('array');
$session->setId($input['sessions'][$index] ?? bin2hex(random_bytes(20)));
$session->start();
$request = Illuminate\Http\Request::create('/auth/github/callback');
$request->setLaravelSession($session);
$app->instance('request', $request);
Illuminate\Support\Facades\Auth::forgetGuards();
$actor = isset($input['actors'][$index]) ? App\Models\User::findOrFail($input['actors'][$index]) : null;
if ($actor) {
    Illuminate\Support\Facades\Auth::guard()->setUser($actor);
    $session->put('security', $input['proofs'][$index]);
}
$request->setUserResolver(fn()=>Illuminate\Support\Facades\Auth::user());
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake([
 'https://github.com/login/oauth/access_token'=>Illuminate\Support\Facades\Http::response(['access_token'=>'synthetic-transient']),
 'https://api.github.com/user'=>Illuminate\Support\Facades\Http::response(['id'=>$input['githubId'] ?? 42]),
 'https://api.github.com/user/emails*'=>Illuminate\Support\Facades\Http::response([['email'=>'callback@example.test','verified'=>true,'primary'=>true]]),
]);
file_put_contents($argv[2].'/ready-'.$index, 'ready');
$deadline = microtime(true)+10;
while (!is_file($argv[2].'/go')) { if (microtime(true)>$deadline) exit(91); usleep(10000); }
try {
 switch ($input['mode']) {
 case 'register': $app->make(App\Services\AuthOnboarding::class)->register(['email'=>$index ? 'RACE-EMAIL@example.test':'race-email@example.test','password'=>'Synthetic-worker-password!2026','locale'=>'en']); break;
 case 'callback': case 'link': $app->make(App\Services\SocialAuth::class)->callback($request,'github',$input['states'][$index],'synthetic-code'); break;
 case 'prepare': $app->make(App\Services\AuthOnboarding::class)->prepare(App\Models\User::findOrFail($input['owner'])); break;
 case 'activate_fixture': Tests\Support\TrialActivationFixture::activate(App\Models\User::findOrFail($input['owner'])); break;
 }
 echo 'accepted';
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
 if (!in_array($e->getStatusCode(),[409,422,403],true)) throw $e;
 echo 'rejected';
} catch (Illuminate\Database\UniqueConstraintViolationException $e) {
 echo 'rejected';
}
WORKER;
        $processes = [];
        try {
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), $directory, (string) $index], base_path(), timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($directory.'/ready-*'));
            file_put_contents($directory.'/go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                $results[] = $process->getOutput();
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
