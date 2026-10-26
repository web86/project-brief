<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'milestone61-e2e-')) {
    throw new RuntimeException('Disposable Milestone 6.1 SQLite fixture required.');
}
$workspace = Workspace::factory()->create(['name' => 'PDF Prototype Lab']);
User::factory()->owner($workspace)->create(['name' => 'Prototype owner', 'email' => 'owner@example.test', 'password' => 'Local-e2e-password!']);
$fixtures = [];
$latin = 'Keep the original customer requirement. Improve navigation, accessibility and product photographs. Çığ ışığı İpek — исходный текст. ';
$chinese = '这是客户输入的原始中文需求，请保留原文，不要自动翻译。改进页面导航、产品图片与移动端体验。';
foreach (['mixed', 'cjk', 'cjk-edge'] as $profile) {
    $description = $profile === 'cjk-edge'
        ? str_repeat($chinese, 220).' https://example.test/'.str_repeat('unbroken', 256)
        : mb_substr(($profile === 'cjk' ? $chinese : '').str_repeat($latin, 5), 0, 300);
    foreach ($profile === 'cjk-edge' ? [2] : [10, 100, 500] as $count) {
        $project = Project::factory()->for($workspace)->create(['title' => 'Website / Çığ / исходный проект', 'currency' => 'USD', 'sections' => ['Navigation', 'Products', 'Account']]);
        $sections = $project->briefSections()->get();
        for ($index = 1; $index <= $count; $index++) {
            $task = Task::factory()->for($project)->create([
                'project_section_id' => $sections[($index - 1) % 3]->id, 'position' => intdiv($index - 1, 3) + 1,
                'title' => 'Website improvement '.$index.' — İpek / задача', 'location' => '/products/'.$index,
                'description' => $description,
                'expected_result' => mb_substr(str_repeat('Navigation is clear, accessible and responsive. ', 4), 0, 100),
                'developer_notes' => mb_substr('PRIVATE_INTERNAL_NOTES '.str_repeat('Preserve original content and existing access controls. ', 3), 0, 80),
                'priority' => 'normal', 'status' => 'done', 'client_approved' => $index % 2 === 0,
                'client_approved_at' => $index % 2 === 0 ? '2026-10-26 10:00:00' : null,
                'estimate_hours' => '8.25', 'price' => '975.50',
            ]);
            $task->attachments()->create(['original_name' => 'requirements-'.$index.'.pdf', 'stored_name' => 'fixture-'.$index, 'mime_type' => 'application/pdf', 'size' => 1024, 'disk' => 'local', 'path' => 'PRIVATE_STORAGE_PATH', 'uploaded_by_type' => 'admin']);
        }
        $token = bin2hex(random_bytes(32));
        $project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $token)]);
        $fixtures[$profile.'-'.$count] = ['project' => $project->uuid, 'token' => $token];
    }
}
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
