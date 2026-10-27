<?php

use App\Models\Project;
use App\Services\IntegrationTokens;
use App\Services\OwnerProvisioning;
use Illuminate\Contracts\Console\Kernel;

$application = $argv[1] ?? '';
$database = getenv('DB_DATABASE') ?: '';
if (getenv('APP_ENV') !== 'local' || getenv('DB_CONNECTION') !== 'sqlite' || ! str_contains($database, 'project-brief-m73-http-')) {
    throw new RuntimeException('Disposable HTTP fixture required.');
}
require $application.'/vendor/autoload.php';
$app = require $application.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$owner = app(OwnerProvisioning::class)->create(['name' => 'HTTP Fixture', 'email' => 'http-fixture@example.test', 'password' => 'Synthetic-http-password!']);
$workspace = $owner->workspaceMembers()->first()->workspace;
$project = Project::create(['workspace_id' => $workspace->id, 'title' => 'HTTP fixture', 'status' => 'active', 'currency' => 'USD', 'sections' => []]);
$client = $project->clients()->create(['name' => 'HTTP client', 'active' => true]);
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $token)]);
$bearer = app(IntegrationTokens::class)->create($workspace->id, ['name' => 'HTTP fixture', 'scopes' => ['projects:read']]);
echo json_encode(['clientToken' => $token, 'project' => $project->uuid, 'bearer' => $bearer['secret']]);
