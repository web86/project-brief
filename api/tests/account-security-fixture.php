<?php

use App\Models\Project;
use App\Models\User;
use App\Services\OwnerProvisioning;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment('production') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'm7-security-')) {
    throw new RuntimeException('Isolated security test database required.');
}
if (($argv[1] ?? '') === 'code') {
    $user = User::where('email', 'security-owner@example.test')->firstOrFail();
    $secret = Fortify::currentEncrypter()->decrypt($user->pending_two_factor_secret ?: $user->two_factor_secret);
    echo json_encode(['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
    exit;
}
$owner = app(OwnerProvisioning::class)->create(['name' => 'Security Owner', 'email' => 'security-owner@example.test', 'password' => 'Owner-test-password!']);
$workspace = $owner->workspaceMembers()->first()->workspace;
$developer = User::factory()->memberOf($workspace)->create(['email' => 'security-developer@example.test', 'password' => 'Developer-test-password!']);
$project = Project::factory()->create(['workspace_id' => $workspace->id]);
$workspaceMember = $developer->workspaceMembers()->first();
$project->members()->create(['workspace_id' => $workspace->id, 'workspace_member_id' => $workspaceMember->id]);
$token = bin2hex(random_bytes(32));
$project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', $token)]);
echo json_encode(['project' => $project->uuid, 'clientToken' => $token]);
