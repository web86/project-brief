<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone50-e2e-')) {
    throw new RuntimeException('Disposable Milestone 5.0 SQLite fixture required.');
}
if (($argv[1] ?? '') === 'fail-delivery') {
    DB::table('webhook_deliveries')->where('status', 'pending')->update(['status' => 'failed', 'attempts' => 1, 'error_code' => 'transport_error']);
    echo '{}';
    exit;
}
$one = Workspace::factory()->create(['name' => 'Web86 Studio']);
$two = Workspace::factory()->create(['name' => 'Partner workspace']);
$owner = User::factory()->owner($one)->memberOf($two)->create(['name' => 'Alex', 'email' => 'owner50@example.test', 'password' => 'Local-e2e-password!']);
$admin = User::factory()->memberOf($one)->create(['name' => 'Developer', 'email' => 'admin50@example.test', 'password' => 'Local-e2e-password!']);
$project = Project::factory()->for($one)->create(['title' => 'Обновление интернет-магазина']);
$project->members()->create(['workspace_id' => $one->id, 'workspace_member_id' => $admin->workspaceMembers()->first()->id]);
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $token)]);
echo json_encode(['project' => $project->uuid, 'token' => $token, 'workspace' => $one->uuid, 'otherWorkspace' => $two->uuid]);
