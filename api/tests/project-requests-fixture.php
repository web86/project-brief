<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\IntegrationTokens;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! ((config('database.default') === 'sqlite' && str_contains(config('database.connections.sqlite.database'), 'brief-provisioning-')) || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'projectbrief_m80_qa'))) {
    throw new RuntimeException('Disposable request QA database required.');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'state') {
    echo json_encode(['requests' => DB::table('project_requests')->count(), 'decisions' => DB::table('project_requests')->pluck('status')->all(),
        'projects' => DB::table('projects')->count(), 'contacts' => DB::table('workspace_clients')->count(), 'memberships' => DB::table('project_clients')->count(),
        'provisionings' => DB::table('project_provisionings')->count(), 'states' => DB::table('project_provisionings')->pluck('invitation_status')->all(),
        'deliveries' => DB::table('notification_deliveries')->where('event', 'project_request.created')->count(),
        'events' => DB::table('webhook_events')->pluck('body')->map(fn ($body) => json_decode($body, true))->all()], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'rotate') {
    $old = DB::table('integration_tokens')->where('uuid', $argv[2])->firstOrFail();
    DB::table('integration_tokens')->where('id', $old->id)->update(['revoked_at' => now()]);
    echo json_encode(app(IntegrationTokens::class)->create($old->workspace_id, ['name' => 'Replacement MODX', 'sourceId' => $old->integration_source_id, 'scopes' => json_decode($old->scopes, true)]), JSON_THROW_ON_ERROR);
    exit;
}
$workspace = Workspace::firstOrFail();
$source = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'web86.site', 'created_at' => now(), 'updated_at' => now()]);
$token = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'MODX requests', 'sourceId' => $source, 'scopes' => ['requests:create', 'requests:read', 'requests:approve']]);
$other = Workspace::factory()->create();
$foreignToken = app(IntegrationTokens::class)->create($other->id, ['name' => 'Foreign CRM', 'scopes' => ['requests:read', 'requests:approve']]);
User::factory()->memberOf($workspace)->create(['email' => 'developer@example.test', 'password' => 'Local-provisioning-test!', 'security_recommendations_seen_at' => now()]);
DB::table('webhook_endpoints')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Request webhook', 'url' => 'https://example.test/webhook',
    'events' => json_encode(['project_request.created', 'project_request.approved', 'project_request.rejected']), 'project_filter' => 'all', 'enabled' => true,
    'secret' => Crypt::encryptString('whsec_'.bin2hex(random_bytes(32))), 'secret_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
echo json_encode(['token' => $token, 'otherToken' => $foreignToken], JSON_THROW_ON_ERROR);
