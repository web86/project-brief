<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectCreation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'brief-live-sync-')) {
    throw new RuntimeException('Disposable administrator QA database required.');
}
if (($argv[1] ?? '') === 'reset-limiter') {
    DB::table('cache')->delete();
    DB::table('cache_locks')->delete();
    exit;
}
$workspace = Workspace::firstOrFail();
foreach (['alex', 'sam'] as $name) {
    User::factory()->memberOf($workspace)->create(['name' => ucfirst($name).' Administrator', 'email' => $name.'@example.test']);
}
$foreign = Workspace::factory()->create(['name' => 'Other workspace']);
$owner = User::where('email', 'owner@example.test')->firstOrFail();
$foreign->members()->create(['user_id' => $owner->id, 'role' => 'admin', 'status' => 'active']);
$foreignOwner = User::factory()->owner($foreign)->create(['email' => 'foreign-owner@example.test']);
$outside = app(ProjectCreation::class)->create($foreign->id, ['title' => 'Foreign project', 'currency' => 'USD'], []);
echo json_encode(['otherWorkspace' => $foreign->uuid, 'foreignProject' => $outside->uuid]);
