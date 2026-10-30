<?php

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectCreation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')) {
    throw new RuntimeException('Disposable Milestone 9.2 QA database required.');
}
if (($argv[1] ?? '') === 'expire-proof') {
    DB::table('account_security_challenges')->where('purpose', 'step_up')->delete();
    exit;
}
$project = Project::firstOrFail();
$owner = User::where('email', 'owner@example.test')->firstOrFail();
$owner->forceFill(['platform_admin' => true, 'preferred_locale' => 'en'])->save();
foreach (['alex', 'sam'] as $name) {
    User::factory()->memberOf($project->workspace)->create(['name' => ucfirst($name).' Administrator', 'email' => $name.'@example.test']);
}
for ($index = 1; $index <= 12; $index++) {
    app(ProjectCreation::class)->create($project->workspace_id, ['title' => 'Additional project '.$index, 'currency' => 'USD'], []);
}
echo json_encode(['owner' => $owner->email]);
