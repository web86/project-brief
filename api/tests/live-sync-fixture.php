<?php

use App\Models\ProjectFeedback;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectCreation;
use App\Services\WorkspaceBranding;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')) {
    throw new RuntimeException('Disposable Live Sync test database required.');
}
if (($argv[1] ?? '') === 'configure') {
    $data = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    $workspace = Workspace::firstOrFail();
    $settings = $workspace->branding;
    $settings['visual']['companyName'] = $data['name'];
    $workspace->update(['branding' => $settings]);
    User::query()->update(['preferred_locale' => $data['locale']]);
    DB::table('project_clients')->update(['preferred_locale' => $data['locale']]);
    exit;
}
$workspace = Workspace::factory()->create(['name' => 'Northline Lab']);
$owner = User::factory()->owner($workspace)->create(['name' => 'Northline Owner', 'email' => 'owner@example.test', 'password' => 'Local-test-password!']);
$developer = User::factory()->owner($workspace)->create(['name' => 'Northline Developer', 'email' => 'developer@example.test', 'password' => 'Local-test-password!']);
$developer->workspaceMembers()->update(['role' => 'admin']);
foreach ([$owner, $developer] as $user) {
    $user->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_map(fn ($index) => 'Live-browser-code-'.$index, range(0, 63))))])->save();
}
$project = app(ProjectCreation::class)->create($workspace->id, ['title' => 'Northline — Live collaboration', 'website_url' => 'https://northline.example.test', 'currency' => 'USD'], ['clientName' => 'Northline Client']);
$section = $project->briefSections()->create(['name' => 'Website', 'position' => 1]);
$task = Task::factory()->for($project)->create(['project_section_id' => $section->id, 'position' => 1, 'title' => 'Product search and responsive navigation', 'description' => 'Keep original requirements. Кириллица / Çığ / 中文. Support keyboard navigation and preserve the selected filters.', 'expected_result' => 'Accessible navigation across desktop and mobile.', 'status' => 'new', 'client_approved' => false, 'developer_notes' => 'Original private technical notes']);
$task->history()->create(['actor_type' => 'admin', 'actor_user_id' => $owner->id, 'event_type' => 'created', 'meta' => ['text' => 'Project requirement created; preserve this complete history entry.'], 'created_at' => now()]);
$task->comments()->create(['project_id' => $project->id, 'author_type' => 'admin', 'author_user_id' => $owner->id, 'text' => 'Initial project conversation.']);
$project->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
$reviewed = app(ProjectCreation::class)->create($workspace->id, ['title' => 'Northline — Completed delivery', 'currency' => 'USD'], ['clientName' => 'Review Author']);
Task::factory()->for($reviewed)->create(['status' => 'done']);
ProjectFeedback::create(['workspace_id' => $workspace->id, 'project_id' => $reviewed->id, 'project_client_id' => $reviewed->clients()->first()->id, 'rating' => 5, 'text' => 'Clear communication and careful implementation. Отличная работа / Teşekkürler / 谢谢。', 'submitted_at' => now()]);
$settings = app(WorkspaceBranding::class)->settings($workspace);
$settings['visual']['companyName'] = 'Northline International Design and Technology Studio';
$image = imagecreatetruecolor(48, 48);
imagefill($image, 0, 0, imagecolorallocate($image, 23, 105, 93));
ob_start();
imagepng($image);
$png = ob_get_clean();
Storage::disk('local')->put('branding/live-logo.png', $png);
$settings['assets']['logo'] = ['token' => str_repeat('a', 48), 'path' => 'branding/live-logo.png', 'extension' => 'png', 'mime' => 'image/png', 'bytes' => strlen($png)];
$workspace->update(['branding' => $settings]);
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $token)]);
echo json_encode(['project' => $project->uuid, 'task' => $task->uuid, 'workspace' => $workspace->uuid, 'token' => $token, 'reviewed' => $reviewed->uuid], JSON_THROW_ON_ERROR);
