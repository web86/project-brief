<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ClientInvitations;
use App\Services\ProjectCreation;
use App\Services\ProjectPermissions;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Fortify\Fortify;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-client-regressions-')) {
    throw new RuntimeException('Disposable client regression database required.');
}
$workspace = Workspace::factory()->create();
$owner = User::factory()->owner($workspace)->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Regression-test-password!']);
$developer = User::factory()->owner($workspace)->create(['name' => 'Developer', 'email' => 'developer@example.test', 'password' => 'Regression-test-password!']);
$developer->workspaceMembers()->update(['role' => 'admin']);
foreach ([$owner, $developer] as $user) {
    $user->forceFill(['preferred_locale' => 'en', 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['regression-'.$user->email]))])->save();
}
$projects = [];
foreach (['ru', 'en', 'tr', 'zh-CN', 'owner', 'developer'] as $language) {
    $project = app(ProjectCreation::class)->create($workspace->id, ['title' => 'Empty project '.$language], ['clientName' => 'Client '.$language, 'clientEmail' => 'client@example.test']);
    $client = $project->clients()->firstOrFail();
    $client->update(['preferred_locale' => $language === 'zh-CN' ? 'zh' : (in_array($language, ['owner', 'developer'], true) ? 'en' : $language)]);
    $link = app(ClientInvitations::class)->issue($project, $client, now()->addDay());
    $project->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id, 'permissions' => array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), false)]);
    $projects[$language] = ['id' => $project->uuid, 'access' => basename($link['url'])];
}
$existing = Project::factory()->for($workspace)->create(['title' => 'Existing project']);
$task = Task::factory()->for($existing)->create(['title' => 'Existing task', 'description' => 'Keep this task unchanged']);
$existing->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id, 'permissions' => array_fill_keys(array_keys(ProjectPermissions::DEFAULTS), false)]);
$link = app(ClientInvitations::class)->issue($existing, $existing->clients()->first(), now()->addDay());
echo json_encode(['projects' => $projects, 'existing' => ['id' => $existing->uuid, 'access' => basename($link['url']), 'taskId' => $task->uuid]], JSON_THROW_ON_ERROR);
