<?php

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectCreation;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone62-')) {
    throw new RuntimeException('Disposable Milestone 6.2 test database required.');
}
$workspace = Workspace::factory()->create(['name' => 'Export smoke lab']);
User::factory()->owner($workspace)->create(['email' => 'owner@example.test', 'password' => 'Local-test-password!']);
$project = app(ProjectCreation::class)->create($workspace->id, ['title' => 'Project / Çığ / 中文 / проект', 'currency' => 'USD'], ['clientName' => 'Test client']);
foreach (['Navigation', 'Products', 'Account'] as $index => $name) {
    $project->briefSections()->create(['name' => $name, 'position' => $index + 1]);
}
$sections = $project->briefSections()->get();
for ($index = 1; $index <= 10; $index++) {
    $task = Task::factory()->for($project)->create([
        'project_section_id' => $sections[($index - 1) % 3]->id,
        'position' => intdiv($index - 1, 3) + 1,
        'title' => 'Export task '.$index.' — Çığ / 中文 / задача',
        'description' => '这是客户输入的原始中文需求，请保留原文，不要自动翻译。 Çığ ışığı İpek — исходный текст. Keep the original requirement.',
        'expected_result' => 'Accessible navigation / 无障碍导航 / удобная навигация.',
        'developer_notes' => 'SYNTHETIC_PRIVATE_NOTES', 'priority' => 'normal', 'status' => $index <= 7 ? 'done' : 'new',
        'client_approved' => $index % 2 === 0, 'estimate_hours' => '8.25', 'price' => '975.50',
    ]);
    $task->attachments()->create(['original_name' => 'requirements-'.$index.'.pdf', 'stored_name' => 'fixture-'.$index, 'mime_type' => 'application/pdf', 'size' => 1, 'disk' => 'local', 'path' => 'SYNTHETIC_PRIVATE_PATH', 'uploaded_by_type' => 'admin']);
}
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $token)]);
echo json_encode(['project' => $project->uuid, 'token' => $token], JSON_THROW_ON_ERROR);
