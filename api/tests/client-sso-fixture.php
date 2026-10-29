<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ClientInvitations;
use App\Services\IntegrationTokens;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! ((config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), 'brief-provisioning-')) || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'projectbrief_m80_qa'))) {
    throw new RuntimeException('Disposable SSO QA database required.');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'state') {
    echo json_encode(['projects' => DB::table('projects')->count(), 'memberships' => DB::table('project_clients')->count(), 'contacts' => DB::table('workspace_clients')->count(),
        'handoffs' => DB::table('client_sso_handoffs')->select('id', 'code_hash', 'project_id', 'consumed_at', 'revoked_at')->get(), 'bindings' => DB::table('client_sso_bindings')->count(),
        'access' => DB::table('project_access_tokens')->whereNotNull('client_sso_handoff_id')->count(), 'events' => DB::table('webhook_events')->pluck('body')->all(), 'operations' => DB::table('provisioning_operations')->count()], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'expire') {
    DB::table('client_sso_handoffs')->where('code_hash', $argv[2])->update(['expires_at' => now()->subSecond()]);
    exit;
}
if ($mode === 'revoke') {
    DB::table('integration_tokens')->where('uuid', $argv[2])->update(['revoked_at' => now()]);
    exit;
}
if ($mode === 'rotate') {
    $old = DB::table('integration_tokens')->where('uuid', $argv[2])->firstOrFail();
    DB::table('integration_tokens')->where('id', $old->id)->update(['revoked_at' => now()]);
    echo json_encode(app(IntegrationTokens::class)->create($old->workspace_id, ['name' => 'Replacement SSO', 'sourceId' => $old->integration_source_id, 'scopes' => json_decode($old->scopes, true)]));
    exit;
}
if ($mode === 'magic') {
    $project = Project::where('uuid', $argv[2])->firstOrFail();
    echo json_encode(app(ClientInvitations::class)->issue($project, $project->clients()->first(), now()->addDay(), false));
    exit;
}
if ($mode === 'email') {
    Project::where('uuid', $argv[2])->firstOrFail()->clients()->update(['email' => $argv[3]]);
    exit;
}
if ($mode === 'client') {
    Project::where('uuid', $argv[2])->firstOrFail()->clients()->update(['active' => $argv[3] === '1']);
    exit;
}
if ($mode === 'legacy') {
    $workspace = Workspace::factory()->create(['name' => 'SSO Studio']);
    $owner = User::factory()->owner($workspace)->create(['email' => 'owner@example.test', 'password' => 'Local-sso-test!']);
    $owner->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['Sso-browser-recovery']))])->save();
    $project = Project::factory()->for($workspace)->create(['title' => 'Existing 0.8.2 project', 'client_email' => 'legacy@example.test']);
    echo json_encode(['project' => $project->uuid], JSON_THROW_ON_ERROR);
    exit;
}
$workspace = Workspace::firstOrFail();
$source = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Trusted client area', 'created_at' => now(), 'updated_at' => now()]);
$token = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'Trusted handoff', 'sourceId' => $source, 'scopes' => ['clients:sso', 'projects:provision', 'requests:create', 'requests:read', 'requests:approve']]);
$otherWorkspace = Workspace::factory()->create();
$otherSource = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $otherWorkspace->id, 'name' => 'Other source', 'sso_enabled' => true, 'sso_origin' => 'https://other.example.test', 'created_at' => now(), 'updated_at' => now()]);
$otherToken = app(IntegrationTokens::class)->create($otherWorkspace->id, ['name' => 'Other workspace', 'sourceId' => $otherSource, 'scopes' => ['clients:sso']]);
$externalToken = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'SSO only', 'sourceId' => $source, 'scopes' => ['clients:sso']]);
echo json_encode(['token' => $token, 'externalToken' => $externalToken, 'otherToken' => $otherToken, 'source' => DB::table('integration_sources')->find($source)->uuid], JSON_THROW_ON_ERROR);
