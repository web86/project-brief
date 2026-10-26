<?php

use App\Http\Controllers\IntegrationController;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WebhookDestination;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use App\Services\WebhookProjectFilter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone501-e2e-')) {
    throw new RuntimeException('Disposable Milestone 5.0.1 SQLite fixture required.');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'process' || $mode === 'inflight' || $mode === 'reject') {
    $app->instance(WebhookDestination::class, new class extends WebhookDestination
    {
        public function resolve(string $url): array
        {
            return ['host' => 'example.com', 'ip' => '93.184.216.34'];
        }
    });
    Http::preventStrayRequests();
    Http::fake(function () use ($mode) {
        if ($mode === 'inflight') {
            file_put_contents(config('database.connections.sqlite.database').'.inflight', 'ready');
            usleep(700000);
        }

        return Http::response('', $mode === 'reject' ? 400 : 204);
    });
    echo json_encode(['processed' => app(WebhookProcessor::class)->process($mode === 'inflight' ? 1 : 20)]);
    exit;
}
if ($mode === 'concurrency-seed') {
    $project = Project::firstOrFail();
    $other = Project::where('workspace_id', $project->workspace_id)->whereKeyNot($project->id)->firstOrFail();
    $endpoint = DB::table('webhook_endpoints')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'Concurrent QA', 'url' => 'https://example.com/events', 'events' => json_encode(['project.updated']), 'project_filter' => 'selected', 'enabled' => true, 'secret' => Crypt::encryptString('synthetic-concurrency-secret'), 'secret_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('webhook_endpoint_projects')->insert(['workspace_id' => $project->workspace_id, 'webhook_endpoint_id' => $endpoint, 'project_id' => $project->id]);
    for ($i = 0; $i < 2; $i++) {
        app(WebhookEvents::class)->project($project, 'project.updated', ['title' => 'Concurrency QA']);
    }
    echo json_encode(['endpoint' => $endpoint, 'targetProject' => $other->uuid]);
    exit;
}
if ($mode === 'change-filter') {
    config(['database.connections.sqlite.transaction_mode' => 'IMMEDIATE', 'database.connections.sqlite.busy_timeout' => 5000]);
    DB::purge();
    DB::transaction(function () use ($argv): void {
        $old = DB::table('webhook_endpoints')->find((int) $argv[2]);
        Workspace::whereKey($old->workspace_id)->lockForUpdate()->firstOrFail();
        $filters = app(WebhookProjectFilter::class);
        $filter = $filters->input($old->workspace_id, ['projectFilter' => 'selected', 'projectIds' => [$argv[3]]], $old);
        $endpoint = DB::table('webhook_endpoints')->where('id', $old->id)->lockForUpdate()->first();
        $filters->replace($endpoint, $filter);
        DB::table('webhook_endpoints')->where('id', $old->id)->update(['secret_version' => $old->secret_version + 1]);
        DB::table('webhook_deliveries')->where('webhook_endpoint_id', $old->id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'endpoint_changed']);
    });
    echo '{}';
    exit;
}
if ($mode === 'delete-endpoint') {
    config(['database.connections.sqlite.transaction_mode' => 'IMMEDIATE', 'database.connections.sqlite.busy_timeout' => 5000]);
    DB::purge();
    $request = Request::create('/api/admin/integrations/webhooks/'.$argv[2], 'DELETE');
    $request->setLaravelSession(app('session')->driver());
    $request->setUserResolver(fn () => User::where('email', 'owner501@example.test')->firstOrFail());
    $response = app(IntegrationController::class)->destroy($request, $argv[2]);
    echo $response->getContent();
    exit;
}
if ($mode === 'cleanup-stats') {
    echo json_encode(['endpoints' => DB::table('webhook_endpoints')->count(), 'deliveries' => DB::table('webhook_deliveries')->count(), 'attempts' => DB::table('webhook_attempts')->count(), 'selections' => DB::table('webhook_endpoint_projects')->count(), 'events' => DB::table('webhook_events')->count(), 'uuid' => DB::table('webhook_endpoints')->value('uuid')]);
    exit;
}
if ($mode === 'stats') {
    echo json_encode(['events' => DB::table('webhook_events')->count(), 'statuses' => DB::table('webhook_deliveries')->orderBy('id')->pluck('status'), 'types' => DB::table('webhook_events')->orderBy('id')->pluck('type')]);
    exit;
}
$one = Workspace::factory()->create(['name' => 'Web86 Studio']);
$two = Workspace::factory()->create(['name' => 'Partner workspace']);
$empty = Workspace::factory()->create(['name' => 'Empty studio']);
$owner = User::factory()->owner($one)->memberOf($two)->memberOf($empty, 'owner')->create(['name' => 'Alex', 'email' => 'owner501@example.test', 'password' => 'Local-e2e-password!']);
$admin = User::factory()->memberOf($one)->create(['name' => 'Developer', 'email' => 'admin501@example.test', 'password' => 'Local-e2e-password!']);
$projects = [];
foreach (['Интернет-магазин', 'Корпоративный сайт', 'Личный кабинет'] as $title) {
    $project = Project::factory()->for($one)->create(['title' => $title]);
    $project->members()->create(['workspace_id' => $one->id, 'workspace_member_id' => $admin->workspaceMembers()->first()->id]);
    $task = Task::factory()->for($project)->create(['title' => 'Идея клиента']);
    $token = bin2hex(random_bytes(32));
    $project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $token)]);
    $projects[] = ['id' => $project->uuid, 'name' => $title, 'task' => $task->uuid, 'token' => $token];
}
echo json_encode(['projects' => $projects, 'workspace' => $one->uuid, 'otherWorkspace' => $two->uuid, 'emptyWorkspace' => $empty->uuid]);
