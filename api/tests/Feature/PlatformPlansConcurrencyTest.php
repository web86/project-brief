<?php

namespace Tests\Feature;

use App\Models\Plans\PlanVersion;
use App\Models\User;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlatformPlansConcurrencyTest extends TestCase
{
    public function test_independent_http_publish_edit_and_timeout_replay_on_mariadb(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent HTTP transactions require disposable MariaDB.');
        }
        $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        try {
            $actor = User::factory()->owner()->create(['platform_admin' => true]);
            DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $actor->id, 'revision' => 1]);
            $this->seed(PlansFoundationSeeder::class);
            $this->actingAs($actor);
            foreach ([['publish', 'publish'], ['publish', 'publish'], ['revise', 'publish']] as $index => $actions) {
                $version = PlanVersion::orderBy('id')->skip($index)->firstOrFail();
                $path = '/api/platform/plans/'.$version->plan->uuid.'/versions/'.$version->uuid;
                $preview = $this->postJson($path.'/previews', ['policy' => 'keep_existing'], ['If-Match' => '"revision:1"', 'Idempotency-Key' => 'race-preview-'.$index])->assertStatus(202)->json('data');
                $keys = $index === 1 ? ['same-http-key', 'same-http-key'] : ['http-one-'.$index, 'http-two-'.$index];
                $outcomes = $this->race($actor->id, $version->id, $actions, $keys, $preview);
                sort($outcomes);
                $this->assertSame($index === 1 ? ['200', '200'] : ['200', '409'], $outcomes);
                $this->assertSame(2, $version->fresh()->revision);
            }
            $this->assertSame(0, DB::table('workspace_subscriptions')->count());
            $this->assertSame(0, DB::table('subscription_changes')->count());
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function race(int $actorId, int $versionId, array $actions, array $keys, array $preview): array
    {
        $directory = sys_get_temp_dir().'/pb-plans-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
(new PHPUnit\TextUI\Configuration\Builder)->build(['worker', '--configuration', $argv[1].'/phpunit.xml']);
$worker = new class('testWorkflow') extends Tests\TestCase {
    public function testWorkflow(): void {}
    public function perform(array $args): void {
        $this->setUp();
        try {
            if (! app()->environment('testing') || Illuminate\Support\Facades\DB::getDatabaseName() !== 'projectbrief_agent_ux_full_qa') { exit(90); }
            $actor = App\Models\User::findOrFail((int) $args[2]);
            $this->actingAs($actor);
            $version = App\Models\Plans\PlanVersion::findOrFail((int) $args[3]);
            $input = app(App\Services\Plans\PlanInput::class)->version($version);
            $preview = json_decode($args[8], true, 16, JSON_THROW_ON_ERROR);
            file_put_contents($args[6].'/ready-'.$args[7], 'ready');
            $deadline = microtime(true) + 10;
            while (! is_file($args[6].'/go')) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
            $path = '/api/platform/plans/'.$input['planId'].'/versions/'.$input['id'];
            $headers = ['If-Match' => '"revision:1"', 'Idempotency-Key' => $args[5]];
            if ($args[4] === 'publish') {
                $response = $this->postJson($path.'/publish', ['policy' => 'keep_existing', 'previewId' => $preview['id'], 'contentHash' => $preview['contentHash']], $headers);
            } else {
                $configuration = $input['configuration'];
                $configuration['translations']['en']['name'] = 'Concurrent HTTP revision';
                $response = $this->putJson($path, $configuration, $headers);
            }
            echo $response->status();
        } finally { $this->tearDown(); }
    }
};
$worker->perform($argv);

PHP;
        $processes = [];
        try {
            foreach ($actions as $index => $action) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $actorId, (string) $versionId, $action, $keys[$index], $directory, (string) $index, json_encode($preview, JSON_THROW_ON_ERROR)], base_path(), timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) !== count($processes) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(count($processes), glob($directory.'/ready-*'), 'Workers did not reach the shared publication barrier.');
            file_put_contents($directory.'/go', 'go');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outcomes[] = $process->getOutput();
            }

            return $outcomes;
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
