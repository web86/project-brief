<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IntegrationTokens;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! ((config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), 'brief-provisioning-')) || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'projectbrief_m80_qa'))) {
    throw new RuntimeException('Disposable provisioning test database required.');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'state') {
    echo json_encode([
        'projects' => DB::table('projects')->count(), 'contacts' => DB::table('workspace_clients')->count(),
        'provisionings' => DB::table('project_provisionings')->count(), 'memberships' => DB::table('project_clients')->count(),
        'states' => DB::table('project_provisionings')->pluck('invitation_status')->all(),
        'events' => DB::table('webhook_events')->pluck('body')->map(fn ($body) => json_decode($body, true))->all(),
    ], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'deactivate') {
    $project = Project::where('uuid', $argv[2])->firstOrFail();
    $project->clients()->update(['active' => false]);
    exit;
}
if ($mode === 'legacy') {
    $workspace = Workspace::factory()->create(['name' => 'Integration Studio']);
    $owner = User::factory()->owner($workspace)->create(['email' => 'owner@example.test', 'password' => 'Local-provisioning-test!']);
    $owner->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['Provisioning-browser-recovery']))])->save();
    $project = Project::factory()->for($workspace)->create(['title' => 'Existing 0.7.8 project', 'client_email' => 'legacy@example.test']);
    echo json_encode(['workspace' => $workspace->uuid, 'project' => $project->uuid], JSON_THROW_ON_ERROR);
    exit;
}
$workspace = Workspace::firstOrFail();
$token = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'Website orders', 'scopes' => ['projects:provision', 'projects:read']]);
$other = Workspace::factory()->create(['name' => 'Other tenant']);
$otherToken = app(IntegrationTokens::class)->create($other->id, ['name' => 'Other orders', 'scopes' => ['projects:provision']]);
DB::table('webhook_endpoints')->insert([
    'uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Fixture webhook', 'url' => 'https://example.test/webhook',
    'events' => json_encode(['project.created', 'project.invitation_changed']), 'project_filter' => 'all',
    'enabled' => true, 'secret' => Crypt::encryptString('whsec_'.bin2hex(random_bytes(32))), 'secret_version' => 1, 'created_at' => now(), 'updated_at' => now(),
]);
echo json_encode(['token' => $token, 'otherToken' => $otherToken], JSON_THROW_ON_ERROR);
