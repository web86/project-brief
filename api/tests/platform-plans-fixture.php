<?php

use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\User;
use App\Services\AuthOnboarding;
use App\Services\Plans\ClientCountingIdentities;
use App\Services\Plans\SubscriptionLifecycle;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains((string) config('database.connections.sqlite.database'), 'brief-platform-plans-')) {
    throw new RuntimeException('Disposable plan-management browser fixture only.');
}
if (($argv[1] ?? null) === 'snapshot') {
    DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
    $cycles = DB::table('workspace_suspension_cycles')->orderBy('id')->get();
    $epochs = DB::table('workspace_execution_epochs')->orderBy('id')->get();
    if ($cycles->count() !== 1 || $epochs->count() !== 4) {
        throw new RuntimeException('Expected the Basic suspension cycle and four execution epochs.');
    }
    echo hash('sha256', json_encode([$cycles, $epochs], JSON_THROW_ON_ERROR));

    return;
}
if (($argv[1] ?? null) === 'lifecycle') {
    $policy = PlatformPolicyVersion::where('state', 'published')->latest('id')->firstOrFail();
    DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
    config(['onboarding.subject_retention_days' => 365]);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->subDays(8));
    try {
        $owner = User::factory()->create(['email' => 'lifecycle-owner@example.test', 'password' => 'Plans-QA-only!84']);
        app(AuthOnboarding::class)->pending($owner);
        $workspace = app(AuthOnboarding::class)->prepare($owner);
        $owner->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_confirmed_at' => CarbonImmutable::now('UTC'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['test-recovery-code', 'lifecycle-step-up'])),
            'recovery_codes_acknowledged_at' => CarbonImmutable::now('UTC'),
        ])->save();
        app(SubscriptionLifecycle::class)->startVerifiedTrial($workspace->id, $owner->id);
        foreach (['First', 'Second'] as $name) {
            Project::factory()->create(['workspace_id' => $workspace->id, 'title' => $name.' synthetic lifecycle project', 'client_email' => strtolower($name).'@example.test']);
        }
        DB::transaction(fn () => app(ClientCountingIdentities::class)->reconcile($workspace->id, 0, (int) DB::table('project_clients')->max('id')));
    } finally {
        CarbonImmutable::setTestNow();
    }
    $versions = DB::table('plan_versions')->whereIn('id', [$policy->trial_version_id, $policy->fallback_version_id])->pluck('uuid', 'id');
    echo json_encode(['owner' => $owner->email, 'password' => 'Plans-QA-only!84', 'workspace' => $workspace->uuid, 'basic' => $versions[$policy->fallback_version_id], 'paid' => $versions[$policy->trial_version_id]]);

    return;
}
$primary = User::factory()->owner()->create(['email' => 'primary@example.test', 'password' => 'Plans-QA-only!84', 'platform_admin' => true]);
$other = User::factory()->owner()->create(['email' => 'diagnostics@example.test', 'password' => 'Plans-QA-only!84', 'platform_admin' => true]);
$primary->forceFill(['two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_map(fn ($i) => 'qa-recovery-'.$i, range(1, 60))))])->save();
DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $primary->id, 'revision' => 1]);
(new PlansFoundationSeeder)->run();
$workspaceId = $primary->workspaceMembers()->firstOrFail()->workspace_id;
for ($index = 0; $index < 3; $index++) {
    $project = Project::factory()->create(['workspace_id' => $workspaceId, 'status' => 'active', 'title' => 'Private fixture title must not appear in inventory']);
    $project->clients()->delete();
    ProjectClient::factory()->create(['project_id' => $project->id, 'email' => 'shared-client@example.test']);
}
echo json_encode(['admin' => $primary->email, 'other' => $other->email, 'password' => 'Plans-QA-only!84']);
