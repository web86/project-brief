<?php

namespace Tests\Feature;

use App\Models\Plans\PlanVersion;
use App\Models\Project;
use App\Services\IntegrationWrites;
use App\Services\Plans\FrozenTransitions;
use App\Services\Plans\LegacyInventory;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\ShadowGuard;
use App\Services\Plans\WorkspaceUsage;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlansPreparationConcurrencyTest extends TestCase
{
    public function test_current_reads_cross_an_old_repeatable_read_snapshot_and_duplicate_previews_serialize(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires exclusive disposable MariaDB independent workers.');
        }
        $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $directory = sys_get_temp_dir().'/d2d-preparation-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        try {
            $project = Project::factory()->create(['status' => 'inactive']);
            $project->clients()->delete();
            $owner = $this->ownerFor($project, ['platform_admin' => true]);
            DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $owner->id, 'revision' => 1]);
            $this->seed(PlansFoundationSeeder::class);
            $basic = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', 'basic'))->firstOrFail();
            $basic = app(PlanCatalog::class)->publish($owner, $basic->id, 1, 'publish');
            app(LegacyInventory::class)->bootstrap();
            app(LegacyInventory::class)->process($project->workspace_id);
            $worker = <<<'WORKER'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || Illuminate\Support\Facades\DB::getDatabaseName() !== 'projectbrief_agent_ux_full_qa') { exit(90); }
$input = json_decode(file_get_contents($argv[2].'/input.json'), true);
if ($argv[3] === 'activate') {
    Illuminate\Support\Facades\DB::transaction(function () use ($input) {
        App\Services\IntegrationWrites::lockWorkspace($input['workspace']);
        App\Models\Project::whereKey($input['project'])->update(['status' => 'active']);
    }, 3);
    echo 'activated';
} else {
    file_put_contents($argv[2].'/ready-'.$argv[3], 'ready');
    $deadline = microtime(true) + 10;
    while (! is_file($argv[2].'/go')) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
    $result = $app->make(App\Services\Plans\FrozenTransitions::class)->create(App\Models\User::findOrFail($input['owner']), $input['workspace'], false, $input['version'], $input['selection'], 1, 'same-intent');
    echo $result['id'];
}
WORKER;
            $input = ['workspace' => $project->workspace_id, 'project' => $project->id, 'owner' => $owner->id, 'version' => $basic->uuid];
            file_put_contents($directory.'/input.json', json_encode($input));
            DB::beginTransaction();
            $this->assertSame(0, DB::table('projects')->where('workspace_id', $project->workspace_id)->where('status', 'active')->count());
            $activate = new Process([PHP_BINARY, '-r', $worker, base_path(), $directory, 'activate'], base_path(), timeout: 20);
            $activate->mustRun();
            $this->assertSame('activated', $activate->getOutput());
            $this->assertSame(0, DB::table('projects')->where('workspace_id', $project->workspace_id)->where('status', 'active')->count(), 'Fixture must retain its older consistent snapshot.');
            IntegrationWrites::lockWorkspace($project->workspace_id);
            $decision = app(ShadowGuard::class)->observe($project->workspace_id, 'project.activate', ['projects.active.max' => 1]);
            if (($decision['state'] ?? null) === 'unknown') {
                $this->assertSame('current_snapshot_refresh_required', $decision['reasonCode']);
                $this->assertSame([], $decision['decisions']);
            } else {
                $this->assertSame(1, collect($decision['decisions'])->firstWhere('key', 'projects.active.max')['currentUsage']);
            }
            DB::commit();
            DB::transaction(function () use ($project): void {
                IntegrationWrites::lockWorkspace($project->workspace_id);
                $this->assertSame(1, app(WorkspaceUsage::class)->measure($project->workspace_id, 'projects.active.max', current: true)['count']);
            });
            $options = app(FrozenTransitions::class)->options($owner, $project->workspace_id, false);
            $input['selection'] = array_map(fn ($rows) => array_column($rows, 'id'), $options['resources']);
            file_put_contents($directory.'/input.json', json_encode($input));
            foreach (['one', 'two'] as $name) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), $directory, $name], base_path(), timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($directory.'/ready-*'));
            file_put_contents($directory.'/go', 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            }
            $this->assertSame($processes[0]->getOutput(), $processes[1]->getOutput());
            $this->assertSame(1, DB::table('plan_previews')->where('kind', 'resource_transition')->count());
            $this->assertSame(1, DB::table('plan_events')->where('action', 'transition.preview_created')->count());
            $this->assertDatabaseCount('workspace_suspension_cycles', 0);
            $this->assertDatabaseCount('workspace_execution_epochs', 0);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            RefreshDatabaseState::$migrated = false;
        }
    }
}
