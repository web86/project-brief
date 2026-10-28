<?php

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectCreation;
use App\Services\ProjectStructure;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Fortify\Fortify;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone62-')) {
    throw new RuntimeException('Disposable Milestone 6.2 test database required.');
}
$workspace = Workspace::factory()->create(['name' => 'Export smoke lab']);
$owner = User::factory()->owner($workspace)->create(['email' => 'owner@example.test', 'password' => 'Local-test-password!']);
$owner->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_map(fn ($index) => 'Export-browser-code-'.$index, range(0, 31))))])->save();
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
$result = ['project' => $project->uuid, 'token' => $token];
if (getenv('EXPORT_LARGE_QA') === '1') {
    $large = app(ProjectCreation::class)->create($workspace->id, ['title' => 'Large export — 500 tasks', 'currency' => 'USD'], ['clientName' => 'Large export client']);
    $section = ProjectStructure::defaultSection($large);
    Task::factory()->count(500)->for($large)->sequence(fn ($sequence) => [
        'project_section_id' => $section->id, 'position' => $sequence->index + 1,
        'title' => 'Large requirement '.($sequence->index + 1).' / Çığ / 中文',
        'description' => 'Preserve every original requirement. Кириллица / Türkçe / 中文需求。',
        'estimate_hours' => '2.50', 'price' => '150.25', 'client_approved' => $sequence->index % 2 === 0,
    ])->create();
    $result['largeProject'] = $large->uuid;
}
echo json_encode($result, JSON_THROW_ON_ERROR);
