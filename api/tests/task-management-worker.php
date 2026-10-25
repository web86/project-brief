<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientTaskDeletion;
use App\Services\ProjectStructure;
use App\Services\TaskAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = $argv[1];
if ($action === 'seed') {
    $project = Project::factory()->create(['sections' => ['Main', 'Target']]);
    $users = [User::factory()->owner($project->workspace)->create(), User::factory()->owner($project->workspace)->create()];
    $tasks = Task::factory()->count(6)->for($project)->create(['section' => 'Main']);
    echo json_encode(['project' => $project->id, 'users' => array_map(fn ($user) => $user->id, $users), 'tasks' => $tasks->pluck('uuid'), 'sections' => $project->briefSections()->pluck('uuid')]);
    exit;
}
if ($action === 'check') {
    $project = Project::findOrFail($argv[2]);
    foreach ($project->briefSections as $section) {
        $positions = $section->tasks()->orderBy('position')->pluck('position')->all();
        if ($positions !== ($positions ? range(1, count($positions)) : [])) {
            throw new RuntimeException('Non-contiguous positions');
        }
    }
    if ($project->tasks()->onlyTrashed()->where('client_approved', true)->exists()) {
        throw new RuntimeException('Approved idea was trashed');
    }
    echo json_encode(['ok' => true, 'tasks' => $project->tasks()->orderBy('position')->get(['uuid', 'project_section_id', 'position', 'client_approved']), 'trashed' => $project->tasks()->onlyTrashed()->count()]);
    exit;
}
$request = Request::create('/api/admin/tasks/'.$argv[3], 'POST');
$request->setLaravelSession($app['session']->driver());
$app->instance('request', $request);
$request->setUserResolver(fn () => User::findOrFail($argv[2]));
$operation = function () use ($action, $request, $argv): void {
    if (str_contains($action, 'approve')) {
        DB::transaction(function () use ($request, $argv): void {
            $task = TaskAccess::task($request, $argv[3], true);
            touch($argv[5]);
            usleep(400000);
            $task->update(['client_approved' => true]);
        });
    } elseif ($action === 'delete') {
        ClientTaskDeletion::delete($request, $argv[3]);
    } else {
        ProjectStructure::placeTask($request, $argv[3], $argv[4], $argv[6] ?? null);
    }
};
try {
    if ($action === 'hold-place') {
        DB::transaction(function () use ($request, $argv, $operation): void {
            ProjectStructure::lock(TaskAccess::task($request, $argv[3])->project);
            touch($argv[5]);
            usleep(400000);
            $operation();
        });
    } else {
        $operation();
    }
    echo json_encode(['ok' => true]);
} catch (HttpExceptionInterface $error) {
    echo json_encode(['ok' => false, 'status' => $error->getStatusCode()]);
}
