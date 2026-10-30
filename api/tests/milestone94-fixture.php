<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceBranding;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! ((config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')) || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'projectbrief_m94_qa'))) {
    throw new RuntimeException('Disposable milestone 9.4 QA database required.');
}
$workspace = Workspace::firstOrFail();
$owner = User::where('email', 'owner@example.test')->firstOrFail();
$owner->forceFill(['platform_admin' => true])->save();
$other = Workspace::factory()->create(['name' => 'Studio Beta']);
$other->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
$brand = app(WorkspaceBranding::class)->settings($other);
$brand['visual'] = ['companyName' => 'Studio Beta — International Product and Engineering', 'primaryColor' => '#7354a3'];
$image = imagecreatetruecolor(48, 48);
imagefill($image, 0, 0, imagecolorallocate($image, 115, 84, 163));
ob_start();
imagepng($image);
$bytes = ob_get_clean();
Storage::disk('local')->put('branding/m94-beta.png', $bytes);
$brand['assets']['logo'] = ['token' => str_repeat('b', 48), 'path' => 'branding/m94-beta.png', 'extension' => 'png', 'mime' => 'image/png', 'bytes' => strlen($bytes)];
$other->update(['branding' => $brand]);
$brand = $workspace->branding;
$brand['visual']['primaryColor'] = '#17695d';
$workspace->update(['branding' => $brand]);
$project = Project::where('workspace_id', $workspace->id)->firstOrFail();
$task = $project->tasks()->firstOrFail();
$task->update(['status' => 'review', 'client_approved' => true]);
$inProgress = Task::factory()->for($project)->create(['status' => 'in_progress', 'title' => 'Locked work in progress', 'client_approved' => true]);
$done = Task::factory()->for($project)->create(['status' => 'done', 'title' => 'Locked completed task', 'client_approved' => true]);
echo json_encode(['otherWorkspace' => $other->uuid, 'review' => $task->uuid, 'inProgress' => $inProgress->uuid, 'done' => $done->uuid], JSON_THROW_ON_ERROR);
