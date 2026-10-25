<?php

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\ProjectNotificationSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationEvents;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone49-e2e-')) {
    throw new RuntimeException('Disposable Milestone 4.9 SQLite fixture required.');
}
if (($argv[1] ?? '') === 'stats') {
    echo json_encode(['comments' => Comment::count(), 'files' => Attachment::count(), 'deliveries' => NotificationDelivery::where('status', 'sent')->count()]);
    exit;
}
$workspace = Workspace::factory()->create(['name' => 'Web86 Studio']);
$developer = User::factory()->memberOf($workspace)->create(['name' => 'Алексей / Alex', 'email' => 'developer@example.test', 'password' => 'Local-e2e-password!']);
$project = Project::factory()->for($workspace)->create(['title' => 'Обновление интернет-магазина']);
$project->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
$task = Task::factory()->for($project)->create(['title' => 'Карточка товара', 'description' => 'Уточнить расположение фотографии и описание товара.']);
$client = $project->clients()->first();
$client->update(['name' => 'Анна', 'email' => 'client@example.test']);
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $token)]);
$developer->forceFill(['notification_preferences' => ['email' => true, 'push' => false, 'telegram' => false]])->save();
$client->forceFill(['notification_preferences' => ['email' => true, 'push' => false, 'telegram' => false]])->save();
ProjectNotificationSetting::where('project_id', $project->id)->update(['delivery_mode' => 'personal', 'event_rules' => array_fill_keys(NotificationEvents::CODES, ['enabled' => true, 'recipients' => ['mode' => 'all', 'ids' => []]])]);
echo json_encode(['project' => $project->uuid, 'task' => $task->uuid, 'token' => $token]);
