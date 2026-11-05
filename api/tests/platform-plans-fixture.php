<?php

use App\Models\DeveloperInvitation;
use App\Models\ExternalAgent;
use App\Models\Plans\PlanVersion;
use App\Models\Plans\PlatformPolicyVersion;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\TaskAgentAssignment;
use App\Models\User;
use App\Services\AgentAccess;
use App\Services\AgentRuns;
use App\Services\AuthOnboarding;
use App\Services\ClientSso;
use App\Services\DeveloperInvitations;
use App\Services\IntegrationTokens;
use App\Services\IntegrationWrites;
use App\Services\Plans\ClientCountingIdentities;
use App\Services\Plans\PlanCatalog;
use App\Services\Plans\PlatformPolicyCatalog;
use App\Services\Plans\SubscriptionLifecycle;
use App\Services\WebhookEvents;
use App\Services\WebhookProcessor;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$disposableSqlite = config('database.default') === 'sqlite'
    && str_contains((string) config('database.connections.sqlite.database'), 'brief-platform-plans-');
$disposableMariaDb = config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === '127.0.0.1'
    && in_array(config('database.connections.mysql.database'), ['brief_phase4b2_browser', 'brief_phase4b2_browser_restored'], true);
if (! app()->environment('testing') || (! $disposableSqlite && ! $disposableMariaDb)) {
    throw new RuntimeException('Disposable plan-management browser fixture only.');
}
if (($argv[1] ?? null) === 'manual-ux') {
    $operator = User::where('email', 'primary@example.test')->firstOrFail();
    foreach (['basic', 'freelancer', 'studio'] as $code) {
        $version = PlanVersion::whereHas('plan', fn ($q) => $q->where('code', $code))->firstOrFail();
        if ($version->state === 'draft') {
            app(PlanCatalog::class)->publish($operator, $version->id, $version->revision, 'step-up-'.$code);
        }
    }
    $policy = app(PlatformPolicyCatalog::class)->publish($operator, PlatformPolicyVersion::firstOrFail()->id, 1, 'manual-ux-policy');
    DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
    $owner = User::factory()->create(['email' => 'manual-ux@example.test', 'password' => 'Plans-QA-only!84']);
    app(AuthOnboarding::class)->pending($owner);
    $workspace = app(AuthOnboarding::class)->prepare($owner);
    $workspace->update(['name' => 'Manual subscription QA']);
    $owner->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['test-recovery-code'])),
        'two_factor_confirmed_at' => now(), 'recovery_codes_acknowledged_at' => now()])->save();
    $trial = app(SubscriptionLifecycle::class)->startVerifiedTrial($workspace->id, $owner->id);
    $developer = User::factory()->memberOf($workspace)->create();
    for ($i = 1; $i <= 12; $i++) {
        $project = Project::create(['workspace_id' => $workspace->id, 'title' => 'Design project '.sprintf('%02d', $i), 'status' => 'active', 'currency' => 'USD']);
        if ($i <= 7) {
            $agent = ExternalAgent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'QA agent '.$i]);
            $project->members()->create(['workspace_id' => $workspace->id, 'external_agent_id' => $agent->id]);
            DB::table('agent_project_grants')->insert(['workspace_id' => $workspace->id, 'external_agent_id' => $agent->id, 'project_id' => $project->id]);
        }
    }
    for ($i = 1; $i <= 9; $i++) {
        $invitation = DeveloperInvitation::factory()->create(['workspace_id' => $workspace->id, 'invited_by' => $owner->id,
            'email' => $i === 9 ? $developer->email : 'manual-invite-'.$i.'@example.test', 'project_ids' => []]);
        app(DeveloperInvitations::class)->issue($invitation);
    }
    echo json_encode(['workspace' => $workspace->uuid, 'name' => $workspace->name, 'owner' => $owner->email, 'trial' => $trial->uuid]);

    return;
}
if (($argv[1] ?? null) === 'snapshot') {
    if ($disposableSqlite) {
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
    }
    $cycles = DB::table('workspace_suspension_cycles')->orderBy('id')->get();
    $epochs = DB::table('workspace_execution_epochs')->orderBy('workspace_id')->orderBy('feature_group')->get();
    if ($cycles->count() !== 1 || $epochs->count() !== 4) {
        throw new RuntimeException('Expected the Basic suspension cycle and four execution epochs.');
    }
    echo hash('sha256', json_encode([$cycles, $epochs], JSON_THROW_ON_ERROR));

    return;
}
if (($argv[1] ?? null) === 'stale-webhook') {
    $delivery = DB::table('webhook_deliveries')->where('uuid', $argv[2] ?? '');
    if ($delivery->where('status', 'failed')->update(['status' => 'pending', 'available_at' => now()]) !== 1) {
        throw new RuntimeException('Expected the unchanged failed delivery after manual retry was refused.');
    }
    Http::fake();
    app(WebhookProcessor::class)->process();
    $result = DB::table('webhook_deliveries')->where('uuid', $argv[2])->first();
    if ($result->status !== 'cancelled' || $result->error_code !== 'subscription_execution_blocked'
        || (int) $result->attempts !== 1 || Http::recorded()->isNotEmpty()) {
        throw new RuntimeException('The independent retry was not cancelled before external transport.');
    }
    echo 'cancelled';

    return;
}
if (($argv[1] ?? null) === 'lifecycle') {
    $policy = PlatformPolicyVersion::where('state', 'published')->latest('id')->firstOrFail();
    DB::table('plan_settings')->where('id', 1)->update(['current_policy_version_id' => $policy->id]);
    config(['onboarding.subject_retention_days' => 365]);
    $realNow = CarbonImmutable::now('UTC');
    CarbonImmutable::setTestNow($realNow->subDays(8));
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
        $probes = ($argv[2] ?? null) === 'rehearsal'
            ? prepareExecutionProbes($workspace->projects()->oldest('id')->firstOrFail(), $realNow)
            : null;
    } finally {
        CarbonImmutable::setTestNow();
    }
    $versions = DB::table('plan_versions')->whereIn('id', [$policy->trial_version_id, $policy->fallback_version_id])->pluck('uuid', 'id');
    echo json_encode(['owner' => $owner->email, 'password' => 'Plans-QA-only!84', 'workspace' => $workspace->uuid, 'basic' => $versions[$policy->fallback_version_id], 'paid' => $versions[$policy->trial_version_id], 'probes' => $probes]);

    return;
}

function prepareExecutionProbes(Project $project, CarbonImmutable $realNow): array
{
    $workspace = $project->workspace_id;
    $tokens = app(IntegrationTokens::class);
    $external = $tokens->create($workspace, ['name' => 'Synthetic external', 'scopes' => ['tasks:write']]);
    $taskPath = '/api/v1/projects/'.$project->uuid.'/tasks';
    $taskPayload = ['title' => 'Preserved rehearsal task', 'location' => 'Общее', 'description' => 'Synthetic fixture', 'priority' => 'normal'];
    $taskKey = 'rehearsal-task';
    $request = Request::create(config('app.url').$taskPath, 'POST', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$external['secret'],
        'HTTP_IDEMPOTENCY_KEY' => $taskKey,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($taskPayload, JSON_THROW_ON_ERROR));
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    if ($response->getStatusCode() !== 201) {
        throw new RuntimeException('Could not create pre-expiry external task receipt: '.$response->getStatusCode());
    }
    $task = $project->tasks()->firstOrFail();
    $agent = ExternalAgent::factory()->create(['workspace_id' => $workspace, 'name' => 'Synthetic rehearsal agent']);
    $project->members()->create(['workspace_id' => $workspace, 'external_agent_id' => $agent->id]);
    DB::table('agent_project_grants')->insert(['workspace_id' => $workspace, 'external_agent_id' => $agent->id, 'project_id' => $project->id]);
    $assignment = TaskAgentAssignment::create(['workspace_id' => $workspace, 'task_id' => $task->id, 'external_agent_id' => $agent->id]);
    $run = DB::transaction(function () use ($assignment) {
        IntegrationWrites::lockWorkspace($assignment->workspace_id);

        return AgentRuns::request(Request::create('/'), $assignment, 'Old rehearsal instruction', 'old-rehearsal-run', false);
    });
    $shared = $tokens->create($workspace, ['name' => 'Synthetic shared executor', 'shared' => true, 'scopes' => AgentAccess::SCOPES]);
    DB::table('agent_credential_links')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace, 'integration_token_id' => DB::table('integration_tokens')->where('uuid', $shared['id'])->value('id'), 'external_agent_id' => $agent->id, 'created_at' => now()]);
    $agentPath = '/api/v1/agent/tasks/'.$task->uuid.'/runs/'.$run->uuid;

    $ssoOrigin = 'https://studio.example.test';
    $source = DB::table('integration_sources')->insertGetId(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace, 'name' => 'Synthetic SSO', 'sso_enabled' => true, 'sso_origin' => $ssoOrigin]);
    DB::table('integration_source_projects')->insert(['workspace_id' => $workspace, 'integration_source_id' => $source, 'project_id' => $project->id]);
    $sso = $tokens->create($workspace, ['name' => 'Synthetic SSO', 'scopes' => ['clients:sso'], 'sourceId' => $source]);
    $token = DB::table('integration_tokens')->where('uuid', $sso['id'])->first();
    $ssoCode = app(ClientSso::class)->issue($token, ['projectId' => $project->uuid, 'externalUserId' => 'synthetic-sso-rehearsal', 'email' => 'first@example.test', 'emailVerified' => true])['code'];
    // Keep the synthetic handoff unexpired until after rearm to distinguish epoch rejection.
    DB::table('client_sso_handoffs')->where('code_hash', hash('sha256', $ssoCode))->update(['expires_at' => $realNow->addHour()]);

    $endpointUuid = (string) Str::uuid();
    $endpoint = DB::table('webhook_endpoints')->insertGetId(['uuid' => $endpointUuid, 'workspace_id' => $workspace, 'name' => 'Synthetic independent retry', 'url' => 'https://example.test/events', 'events' => '[]', 'secret' => 'unused-synthetic-fixture']);
    app(WebhookEvents::class)->enqueue($workspace, 'webhook.test', [], $endpoint);
    DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint)->update(['attempts' => 1, 'status' => 'failed']);
    $delivery = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $endpoint)->value('uuid');

    return [
        'taskPath' => $taskPath, 'taskKey' => $taskKey, 'taskPayload' => $taskPayload, 'externalToken' => $external['secret'],
        'agentPath' => $agentPath, 'agentId' => $agent->uuid, 'agentToken' => $shared['secret'],
        'ssoOrigin' => $ssoOrigin, 'ssoCode' => $ssoCode,
        'delivery' => $delivery,
        'retryPath' => '/api/admin/integrations/webhooks/'.$endpointUuid.'/deliveries/'.$delivery.'/retry',
    ];
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
