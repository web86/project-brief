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

class PlansFoundationConcurrencyTest extends TestCase
{
    public function test_independent_mariadb_workers_serialize_publication_edit_and_idempotent_replay(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real independent-process locking requires the disposable MariaDB runner.');
        }
        $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        try {
            $actor = User::factory()->create(['platform_admin' => true]);
            DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $actor->id, 'revision' => 1]);
            $this->seed(PlansFoundationSeeder::class);
            $drafts = PlanVersion::orderBy('id')->get();
            $outcomes = $this->race($actor->id, $drafts[0]->id, ['publish', 'publish'], ['different-one', 'different-two']);
            sort($outcomes);
            $this->assertSame(['applied', 'conflict'], $outcomes);
            $this->assertSame('published', $drafts[0]->fresh()->state);
            $this->assertSame(2, $drafts[0]->fresh()->revision);
            $outcomes = $this->race($actor->id, $drafts[1]->id, ['publish', 'publish'], ['same-key', 'same-key']);
            $this->assertSame(['applied', 'applied'], $outcomes);
            $this->assertSame(2, DB::table('plan_events')->where('action', 'plan.publish')->count());
            $outcomes = $this->race($actor->id, $drafts[2]->id, ['revise', 'publish'], ['edit-race', 'publish-race']);
            sort($outcomes);
            $this->assertSame(['applied', 'conflict'], $outcomes);
            $this->assertSame(2, $drafts[2]->fresh()->revision);
            $this->assertSame(4, DB::table('plan_events')->count());
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function race(int $actorId, int $versionId, array $actions, array $keys): array
    {
        $directory = sys_get_temp_dir().'/pb-plans-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || Illuminate\Support\Facades\DB::getDatabaseName() !== 'projectbrief_agent_ux_full_qa') { exit(90); }
$actor = App\Models\User::findOrFail((int) $argv[2]);
$catalog = $app->make(App\Services\Plans\PlanCatalog::class);
$configuration = $catalog->configuration(App\Models\Plans\PlanVersion::findOrFail((int) $argv[3]));
file_put_contents($argv[6].'/ready-'.$argv[7], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[6].'/go')) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
try {
    if ($argv[4] === 'publish') {
        $catalog->publish($actor, (int) $argv[3], 1, $argv[5]);
    } else {
        $configuration['translations']['en']['name'] = 'Concurrent revision';
        $catalog->revise($actor, (int) $argv[3], 1, $configuration, $argv[5]);
    }
    echo 'applied';
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    if ($e->getStatusCode() !== 409) { throw $e; }
    echo 'conflict';
}
PHP;
        $processes = [];
        try {
            foreach ($actions as $index => $action) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $actorId, (string) $versionId, $action, $keys[$index], $directory, (string) $index], base_path(), timeout: 20);
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
