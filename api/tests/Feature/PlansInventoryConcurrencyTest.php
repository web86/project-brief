<?php

namespace Tests\Feature;

use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Plans\SubscriptionPeriod;
use App\Models\Plans\WorkspaceSubscription;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Plans\EffectiveParticipation;
use App\Services\Plans\LegacyInventory;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlatformPolicyCatalog;
use App\Services\Plans\SubscriptionClock;
use App\Services\Plans\WorkspaceUsage;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlansInventoryConcurrencyTest extends TestCase
{
    public function test_independent_workers_resume_backfill_and_serialize_invites_and_project_changes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires isolated MariaDB independent workers.');
        }
        $this->assertSame('projectbrief_agent_ux_full_qa', DB::getDatabaseName());
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        try {
            $project = Project::factory()->create();
            $project->clients()->delete();
            $this->ownerFor($project);
            ProjectClient::factory()->count(210)->sequence(fn ($sequence) => ['email' => 'race-'.$sequence->index.'@example.test'])->create(['project_id' => $project->id]);
            $inventory = app(LegacyInventory::class);
            $inventory->bootstrap();
            $this->assertSame(['applied', 'applied'], $this->race($project->workspace_id, 0, ['inventory', 'inventory'], ['one', 'two']));
            $this->assertDatabaseCount('workspace_subscriptions', 1);
            $this->assertDatabaseCount('subscription_periods', 1);
            $this->assertDatabaseCount('entitlement_client_identities', 210);
            $this->assertDatabaseCount('entitlement_client_observations', 210);
            $this->assertSame('processed', DB::table('plan_inventory_items')->value('state'));
            $this->assertSame(210, app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max')['count']);
            $this->assertSame(['applied', 'applied'], $this->race($project->workspace_id, 0, ['invite', 'invite'], ['one', 'two']));
            $this->assertDatabaseCount('developer_invitations', 2);
            $this->assertDatabaseCount('entitlement_capacity_reservations', 2);
            $this->assertSame(2, app(WorkspaceUsage::class)->measure($project->workspace_id, 'developers.max')['count']);
            $this->assertSame(['applied', 'applied'], $this->race($project->workspace_id, 0, ['inventory', 'change'], ['one', 'two']));
            $this->assertSame(0, app(WorkspaceUsage::class)->measure($project->workspace_id, 'projects.active.max')['count']);
            $this->assertSame(0, app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max')['count']);
            $inventory->process($project->workspace_id, refresh: true);
            $inventory->process($project->workspace_id);
            $this->assertDatabaseCount('entitlement_client_observations', 211);
            $project->refresh()->update(['status' => 'active']);
            $this->assertSame(211, app(WorkspaceUsage::class)->measure($project->workspace_id, 'clients.max')['count']);
            $this->assertDatabaseCount('owner_trial_claims', 0);
            $migration = require database_path('migrations/2026_11_02_220732_add_plan_inventory_accounting.php');
            $migration->up();
            DB::statement('ALTER TABLE plan_inventory_items DROP INDEX pi_item_state_idx');
            $migration->up();
            $this->assertTrue(Schema::hasIndex('plan_inventory_items', 'pi_item_state_idx'));
            $extraProjects = [];
            for ($index = 0; $index < 1000; $index++) {
                $extraProjects[] = ['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'title' => 'Synthetic query-plan fixture', 'currency' => 'USD', 'status' => $index % 3 ? 'active' : 'inactive'];
            }
            DB::table('projects')->insert($extraProjects);
            $countPlan = DB::select('EXPLAIN SELECT COUNT(*) FROM projects WHERE workspace_id = ? AND deleted_at IS NULL AND status = ?', [$project->workspace_id, 'active']);
            $this->assertSame('pf_projects_usage_idx', $countPlan[0]->key);
            $plan = DB::select('EXPLAIN SELECT id FROM projects WHERE workspace_id = ? AND deleted_at IS NULL AND status = ? ORDER BY id', [$project->workspace_id, 'active']);
            $this->assertNotEmpty($plan[0]->possible_keys);
            $this->assertStringContainsString('pf_projects_usage_idx', $plan[0]->possible_keys);
            $this->assertConcurrentExpiry();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function assertConcurrentExpiry(): void
    {
        $workspace = Workspace::factory()->create();
        Project::factory()->create(['workspace_id' => $workspace->id, 'status' => 'active']);
        $actor = User::factory()->create(['platform_admin' => true]);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $actor->id, 'revision' => 1]);
        $this->seed(PlansFoundationSeeder::class);
        foreach (['basic', 'studio'] as $code) {
            $draft = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', $code))->firstOrFail();
            app(PlanCatalog::class)->publish($actor, $draft->id, 1, 'race-'.$code);
        }
        $policy = app(PlatformPolicyCatalog::class)->publish($actor, PlatformPolicyVersion::firstOrFail()->id, 1, 'race-policy');
        $facts = app(SubscriptionClock::class)->period('trial', CarbonImmutable::now('UTC')->subDays(8), 7);
        $subscription = WorkspaceSubscription::create([
            'workspace_id' => $workspace->id,
            'materialized_phase' => 'trial',
            'enforcement_mode' => 'enforce',
            'revision' => 1,
            'next_boundary_at' => $facts['service_ends_at'],
        ]);
        $period = SubscriptionPeriod::create($facts + [
            'workspace_id' => $workspace->id,
            'subscription_id' => $subscription->id,
            'entitlement_version_id' => $policy->trial_version_id,
            'policy_version_id' => $policy->id,
            'fallback_version_id' => $policy->fallback_version_id,
            'approval_kind' => 'trial_verification',
        ]);
        $subscription->update(['current_period_id' => $period->id]);

        $this->assertSame(['applied', 'applied', 'applied'], $this->race($workspace->id, 0, ['expiry', 'expiry', 'change'], ['one', 'two', 'three']));
        $this->assertSame('free', $subscription->fresh()->materialized_phase);
        $this->assertSame(2, $subscription->fresh()->revision);
        $this->assertSame(2, SubscriptionPeriod::where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, DB::table('workspace_suspension_cycles')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(4, DB::table('workspace_execution_epochs')->where('workspace_id', $workspace->id)->where('state', 'blocked')->count());
        $results = $this->race($workspace->id, 0, ['allocate-project', 'allocate-project'], ['one', 'two']);
        sort($results);
        $this->assertSame(['applied', 'denied'], $results);
        $this->assertSame(1, app(EffectiveParticipation::class)->projects($workspace->id)->count());
    }

    private function race(int $workspaceId, int $versionId, array $actions, array $keys): array
    {
        $directory = sys_get_temp_dir().'/pb-plans-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || Illuminate\Support\Facades\DB::getDatabaseName() !== 'projectbrief_agent_ux_full_qa') { exit(90); }
$workspaceId = (int) $argv[2];
$inventory = $app->make(App\Services\Plans\LegacyInventory::class);
file_put_contents($argv[6].'/ready-'.$argv[7], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[6].'/go')) { if (microtime(true) > $deadline) { exit(91); } usleep(10000); }
if ($argv[4] === 'inventory') {
    $inventory->process($workspaceId);
} elseif ($argv[4] === 'expiry') {
    $app->make(App\Services\Plans\SubscriptionLifecycle::class)->catchUp($workspaceId);
} elseif ($argv[4] === 'allocate-project') {
    try {
        $app->make(App\Services\ProjectCreation::class)->create($workspaceId, ['title' => 'Concurrent capacity fixture'], []);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        if ($error->getStatusCode() !== 403 || $error->getMessage() !== 'subscription_capacity_exceeded') {
            throw $error;
        }
        echo 'denied';
        exit;
    }
} elseif ($argv[4] === 'invite') {
    Illuminate\Support\Facades\DB::transaction(function () use ($workspaceId, $app): void {
        App\Services\IntegrationWrites::lockWorkspace($workspaceId);
        $owner = App\Models\WorkspaceMember::where('workspace_id', $workspaceId)->where('role', 'owner')->firstOrFail();
        $i = App\Models\DeveloperInvitation::factory()->create(['workspace_id' => $workspaceId, 'invited_by' => $owner->user_id, 'email' => 'same-person@example.test']);
        $app->make(App\Services\DeveloperInvitations::class)->issue($i);
    }, 3);
} else {
    Illuminate\Support\Facades\DB::transaction(function () use ($workspaceId): void {
        App\Services\IntegrationWrites::lockWorkspace($workspaceId);
        $p = App\Models\Project::where('workspace_id', $workspaceId)->firstOrFail();
        $p->update(['status' => 'inactive']);
        App\Models\ProjectClient::factory()->create(['project_id' => $p->id, 'email' => 'changed@example.test']);
    }, 3);
}
echo 'applied';
PHP;
        $processes = [];
        try {
            foreach ($actions as $index => $action) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $workspaceId, (string) $versionId, $action, $keys[$index], $directory, (string) $index], base_path(), timeout: 20);
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
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
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
