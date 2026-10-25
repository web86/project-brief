<?php

use App\Models\DeveloperInvitation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone48-e2e-')) {
    throw new RuntimeException('Disposable SQLite fixture required.');
}
$workspace = Workspace::factory()->create(['name' => 'Web86 Studio']);
$owner = User::factory()->owner($workspace)->create(['name' => 'Алексей Морозов', 'email' => 'owner@example.test', 'password' => 'Local-e2e-password!', 'preferred_locale' => 'ru']);
$first = Project::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Интернет-магазин — обновление', 'website_url' => 'https://example.test']);
$second = Project::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Личный кабинет клиентов']);
$task = Task::factory()->create(['project_id' => $first->id, 'project_section_id' => $first->briefSections()->first()->id, 'title' => 'Фотографии товаров на телефоне']);
$developer = User::factory()->memberOf($workspace)->create(['name' => 'Мария Волкова', 'email' => 'maria@example.test', 'password' => 'Local-e2e-password!']);
$first->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
if (getenv('M481_EXPIRED_TOKEN')) {
    User::factory()->create(['name' => 'Existing developer', 'email' => 'Legacy@Example.test', 'password' => 'Local-e2e-password!']);
    DeveloperInvitation::factory()->create(['workspace_id' => $workspace->id, 'invited_by' => $owner->id, 'email' => 'expired@example.test', 'project_ids' => [$first->id], 'token_hash' => hash('sha256', getenv('M481_EXPIRED_TOKEN')), 'expires_at' => now()->subSecond()]);
}
$image = imagecreatetruecolor(380, 540);
imagefill($image, 0, 0, imagecolorallocate($image, 80, 100, 210));
imagepng($image, getenv('M48_AVATAR_FILE'));
echo json_encode(['first' => $first->uuid, 'second' => $second->uuid, 'task' => $task->uuid]);
