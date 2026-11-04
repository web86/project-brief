<?php

namespace Tests\Feature;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Plans\SubscriptionPeriod;
use App\Models\User;
use App\Services\AuthOnboarding;
use App\Services\Plans\LegacyInventory;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlatformPolicyCatalog;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CanaryActivationConcurrencyTest extends TestCase
{
    public function test_independent_canary_activations_replay_or_conflict_without_a_second_trial(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent activation transactions require disposable MariaDB.');
        }
        $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;

        config(['onboarding.subject_retention_days' => 365]);
        $primary = User::factory()->owner()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $primary->id, 'revision' => 1]);
        $this->seed(PlansFoundationSeeder::class);
        foreach (['basic', 'studio'] as $code) {
            $draft = PlanVersion::whereHas('plan', fn ($query) => $query->where('code', $code))->firstOrFail();
            app(PlanCatalog::class)->publish($primary, $draft->id, 1, 'race-'.$code);
        }
        $policy = app(PlatformPolicyCatalog::class)->publish($primary, PlatformPolicyVersion::firstOrFail()->id, 1, 'race-policy');
        DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
        $inventory = app(LegacyInventory::class);
        $inventory->bootstrap();
        $inventory->process($primary->workspaceMembers()->firstOrFail()->workspace_id);

        foreach ([['same', 'same'], ['first', 'second']] as $index => $keys) {
            $owner = User::factory()->owner()->create();
            $owner->workspaceMembers()->delete();
            app(AuthOnboarding::class)->pending($owner);
            app(AuthOnboarding::class)->prepare($owner);
            $claim = DB::table('owner_trial_claims')->where('owner_user_id', $owner->id)->value('uuid');
            $revision = DB::table('plan_settings')->where('id', 1)->value('revision');
            $this->assertSame($index === 0 ? ['200', '200'] : ['200', '409'], $this->race($primary->id, $revision, $claim, $keys));
            $this->assertSame($index + 1, SubscriptionPeriod::where('kind', 'trial')->count());
            $this->assertSame($index + 1, DB::table('plan_events')->where('action', 'canary.admit')->count());
        }
    }

    private function race(int $actor, int $revision, string $claim, array $keys): array
    {
        $directory = sys_get_temp_dir().'/d2d-canary-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || Illuminate\Support\Facades\DB::getDatabaseName() !== 'projectbrief_agent_ux_full_qa') { exit(90); }
config([
    'onboarding.trial_activation_requested' => true,
    'onboarding.subject_retention_days' => 365,
    'mail.default' => 'smtp',
    'mail.mailers.smtp.host' => 'synthetic.example.test',
    'mail.from.address' => 'sender@example.test',
]);
file_put_contents($argv[2].'/ready-'.$argv[3], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[2].'/go')) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
try {
    app(App\Services\Plans\CommercialCanary::class)->admit(App\Models\User::findOrFail($argv[4]), (int) $argv[5], $argv[6], $argv[7]);
    echo '200';
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $failure) {
    echo $failure->getStatusCode();
}
PHP;
        $processes = [];
        try {
            foreach ($keys as $index => $key) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), $directory, (string) $index, (string) $actor, (string) $revision, $claim, $key], base_path(), timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($directory.'/ready-*'));
            file_put_contents($directory.'/go', 'go');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outcomes[] = $process->getOutput();
            }
            sort($outcomes);

            return $outcomes;
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            File::deleteDirectory($directory);
        }
    }
}
