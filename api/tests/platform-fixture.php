<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ApiUsage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains((string) config('database.connections.sqlite.database'), 'brief-platform-')) {
    throw new RuntimeException('Disposable platform browser QA only.');
}
if (($argv[1] ?? '') === 'expire') {
    DB::table('account_security_challenges')->where('purpose', 'step_up')->update(['expires_at' => now()->subMinute()]);
    exit;
}
$codes = array_map(fn ($i) => 'qa-recovery-'.$i, range(1, 40));
$admin = User::factory()->owner()->create(['email' => 'platform@example.test', 'password' => 'Platform-QA-only!84', 'platform_admin' => true]);
$admin->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes))])->save();
$admin->workspaceMembers()->delete();
$workspace = Workspace::factory()->create(['name' => 'North workspace']);
$owner = User::factory()->owner($workspace)->create(['email' => 'owner@example.test', 'password' => 'Platform-QA-only!84']);
$project = Project::factory()->create(['workspace_id' => $workspace->id, 'title' => 'PRIVATE PROJECT BODY']);
Workspace::factory()->count(27)->create();
for ($i = 0; $i < 4; $i++) {
    $request = Request::create('/api/v1/projects?secret=SHOULD-NOT-APPEAR');
    $request->setRouteResolver(fn () => new Route('GET', 'api/v1/projects/{uuid}', fn () => null));
    app(ApiUsage::class)->record($request, [200, 403, 429, 500][$i], 1000 * ($i + 1));
}
echo json_encode(['admin' => $admin->email, 'owner' => $owner->email, 'workspace' => $workspace->uuid, 'password' => 'Platform-QA-only!84']);
