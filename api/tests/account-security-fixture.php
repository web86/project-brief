<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\OwnerProvisioning;
use App\Services\WorkspaceBranding;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment('production') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'm7-security-')) {
    throw new RuntimeException('Isolated security test database required.');
}
if (($argv[1] ?? '') === 'expire-proof') {
    $user = User::where('email', ($argv[2] ?? '') === 'developer' ? 'security-developer@example.test' : 'security-owner@example.test')->firstOrFail();
    DB::table('account_security_challenges')->where('user_id', $user->id)->where('purpose', 'step_up')->delete();
    exit;
}
if (($argv[1] ?? '') === 'reset-limiter') {
    Artisan::call('cache:clear');
    exit;
}
if (($argv[1] ?? '') === 'branding-workspaces') {
    $owner = User::where('email', 'security-owner@example.test')->firstOrFail();
    $workspace = $owner->workspaceMembers()->orderBy('workspace_id')->firstOrFail()->workspace;
    $settings = app(WorkspaceBranding::class)->settings($workspace);
    $settings['visual'] = ['companyName' => 'ASTER Studio', 'primaryColor' => '#17695d'];
    $workspace->update(['branding' => $settings]);
    $other = Workspace::create(['name' => 'North Workspace']);
    $other->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
    $settings['visual'] = ['companyName' => 'North Studio', 'primaryColor' => '#7354a3'];
    $other->update(['branding' => $settings]);
    echo json_encode(['first' => $workspace->uuid, 'second' => $other->uuid]);
    exit;
}
if (($argv[1] ?? '') === 'platform-owner') {
    User::where('email', 'security-owner@example.test')->firstOrFail()->forceFill(['platform_admin' => true])->save();
    exit;
}
if (in_array($argv[1] ?? '', ['expire-register', 'expire-confirm'], true)) {
    DB::table('account_security_challenges')->where('purpose', ($argv[1] === 'expire-register' ? 'passkey_register' : 'passkey_confirm'))->update(['expires_at' => now()->subSecond()]);
    exit;
}
if (($argv[1] ?? '') === 'code') {
    $user = User::where('email', ($argv[2] ?? '') === 'developer' ? 'security-developer@example.test' : 'security-owner@example.test')->firstOrFail();
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
