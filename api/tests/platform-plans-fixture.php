<?php

use App\Models\User;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains((string) config('database.connections.sqlite.database'), 'brief-platform-plans-')) {
    throw new RuntimeException('Disposable plan-management browser fixture only.');
}
$primary = User::factory()->owner()->create(['email' => 'primary@example.test', 'password' => 'Plans-QA-only!84', 'platform_admin' => true]);
$other = User::factory()->owner()->create(['email' => 'diagnostics@example.test', 'password' => 'Plans-QA-only!84', 'platform_admin' => true]);
$primary->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_map(fn ($i) => 'qa-recovery-'.$i, range(1, 60))))])->save();
DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $primary->id, 'revision' => 1]);
(new PlansFoundationSeeder)->run();
echo json_encode(['admin' => $primary->email, 'other' => $other->email, 'password' => 'Plans-QA-only!84']);
