<?php

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone483-e2e-')) {
    throw new RuntimeException('Disposable Milestone 4.8.3 SQLite fixture required.');
}
if (($argv[1] ?? '') === 'reset') {
    DB::table('comment_reads')->delete();
    DB::table('client_comment_reads')->delete();
    Comment::where('text', 'like', 'E2E sent %')->delete();
    exit;
}
$workspace = Workspace::factory()->create(['name' => 'Web86 Studio']);
$owner = User::factory()->owner($workspace)->create(['name' => 'Владелец / Owner', 'email' => 'owner@example.test', 'password' => 'Local-e2e-password!']);
$developer = User::factory()->memberOf($workspace)->create(['name' => 'Разработчик / Developer', 'email' => 'developer@example.test', 'password' => 'Local-e2e-password!']);
$writer = User::factory()->memberOf($workspace)->create(['name' => 'Алексей / Alex']);
$project = Project::factory()->for($workspace)->create(['title' => 'Storefront — обновление сайта']);
$second = Project::factory()->for($workspace)->create(['title' => 'Second project']);
foreach ([$project, $second] as $assigned) {
    foreach ([$developer, $writer] as $user) {
        $assigned->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $user->workspaceMembers()->first()->id]);
    }
}
$task = Task::factory()->for($project)->create(['title' => 'Update product gallery', 'developer_notes' => 'PRIVATE_NOTES']);
$general = $tasks = [];
foreach (['general', 'tasks'] as $kind) {
    for ($i = 0; $i < 70; $i++) {
        $comment = Comment::create(['project_id' => $kind === 'general' ? $project->id : null, 'task_id' => $kind === 'tasks' ? $task->id : null, 'author_type' => 'admin', 'author_user_id' => $writer->id, 'text' => ($kind === 'general' ? 'Обсуждаем результат проекта / Project discussion ' : 'Уточняем детали задачи / Task comment ').($i + 1), 'created_at' => now()->subMinutes(100 - $i)]);
        if ($kind === 'general') {
            $general[] = $comment->uuid;
        } else {
            $tasks[] = $comment->uuid;
        }
    }
}
for ($i = 0; $i < 2; $i++) {
    Comment::create(['project_id' => $second->id, 'author_type' => 'admin', 'author_user_id' => $writer->id, 'text' => 'Only second project '.$i]);
}
$client = $project->clients()->first();
$client->update(['name' => 'Анна / Anna', 'email' => 'client@example.test']);
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $token)]);
echo json_encode(['project' => $project->uuid, 'second' => $second->uuid, 'task' => $task->uuid, 'generalTarget' => $general[7], 'taskTarget' => $tasks[7], 'token' => $token]);
