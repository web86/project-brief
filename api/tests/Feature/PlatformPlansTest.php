<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountProfile;
use Database\Seeders\PlansFoundationSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformPlansTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function primary(): User
    {
        $user = User::factory()->owner()->create(['platform_admin' => true]);
        $this->seed(PlansFoundationSeeder::class);
        DB::table('platform_plan_authority')->insert(['id' => 1, 'primary_user_id' => $user->id, 'revision' => 1]);
        $this->actingAs($user);

        return $user;
    }

    private function headers(int $revision = 1, ?string $key = null): array
    {
        return ['If-Match' => '"revision:'.$revision.'"', 'Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    private function draft(): array
    {
        return $this->getJson('/api/platform/plans')->assertOk()->json('data.0');
    }

    public function test_primary_only_access_and_existing_strong_fresh_session_gates(): void
    {
        $this->getJson('/api/platform/plans')->assertUnauthorized();
        $primary = $this->primary();
        $this->getJson('/api/platform/plans')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->travel(601)->seconds();
        $this->getJson('/api/platform/plans')->assertOk();
        $this->postJson('/api/platform/plans')->assertStatus(423)->assertJsonPath('code', 'security_step_up_required');
        $this->actingAs($primary)->withSession(['security.method' => 'password']);
        $this->getJson('/api/platform/plans')->assertForbidden();
        $other = User::factory()->owner()->create(['platform_admin' => true]);
        $this->actingAs($other)->getJson('/api/platform/overview')->assertOk();
        $this->getJson('/api/platform/plans')->assertForbidden();
        $this->get('/platform/plans')->assertForbidden();
    }

    public function test_draft_preview_publish_replay_and_parent_binding(): void
    {
        $this->primary();
        $row = $this->draft();
        $url = '/api/platform/plans/'.$row['id'].'/versions/'.$row['versions'][0]['id'];
        $version = $this->getJson($url)->assertOk()->json('data');
        $configuration = $version['configuration'];
        $configuration['translations']['en']['name'] = 'Fourth configurable plan';
        $created = $this->postJson('/api/platform/plans', ['code' => 'fourth', ...$configuration], $this->headers(key: 'create-fourth'))->assertCreated()->json('data');
        $this->postJson('/api/platform/plans', ['code' => 'fourth', ...$configuration], $this->headers(key: 'create-fourth'))->assertCreated()->assertJsonPath('data.id', $created['id']);
        $preview = $this->postJson($url.'/previews', ['policy' => 'keep_existing'], $this->headers())->assertStatus(202)->json('data');
        $this->assertSame('unavailable', $preview['summary']['impact']['state']);
        $body = ['previewId' => $preview['id'], 'contentHash' => $preview['contentHash'], 'policy' => 'keep_existing'];
        $this->postJson($url.'/publish', $body, $this->headers(key: 'publish'))->assertOk()->assertJsonPath('data.state', 'published');
        $this->postJson($url.'/publish', $body, $this->headers(key: 'publish'))->assertOk();
        $this->putJson($url, $configuration, $this->headers(2))->assertConflict();
        $this->getJson('/api/platform/plans/'.$created['planId'].'/versions/'.$row['versions'][0]['id'])->assertNotFound();
        $this->assertDatabaseCount('workspace_subscriptions', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
    }

    public function test_stale_preview_revision_conflicts_unknown_input_and_missing_headers(): void
    {
        $this->primary();
        $row = $this->draft();
        $url = '/api/platform/plans/'.$row['id'].'/versions/'.$row['versions'][0]['id'];
        $config = $this->getJson($url)->json('data.configuration');
        $this->putJson($url, $config, ['Idempotency-Key' => 'missing-rev'])->assertStatus(428);
        $this->postJson($url.'/previews', ['policy' => 'apply_now'], $this->headers())->assertUnprocessable();
        $preview = $this->postJson($url.'/previews', ['policy' => 'keep_existing'], $this->headers())->assertStatus(202)->json('data');
        $config['translations']['ru']['name'] = 'Новая редакция';
        $this->putJson($url, $config, $this->headers())->assertOk();
        $this->postJson($url.'/publish', ['previewId' => $preview['id'], 'contentHash' => $preview['contentHash'], 'policy' => 'keep_existing'], $this->headers())->assertConflict();
        $this->putJson($url, [...$config, 'paid' => true], $this->headers(2))->assertUnprocessable();
        $this->putJson($url, $config, $this->headers())->assertConflict();
    }

    public function test_operator_bootstrap_and_transfer_are_interactive_verified_and_audited(): void
    {
        $one = User::factory()->owner()->create(['platform_admin' => true]);
        $two = User::factory()->owner()->create(['platform_admin' => true]);
        $this->artisan('platform:plan-authority', ['user' => $one->id, '--no-interaction' => true])->assertFailed();
        $question = 'After independent operator verification, type: ASSIGN PLAN AUTHORITY '.$one->id.' REVISION 0';
        $this->artisan('platform:plan-authority', ['user' => $one->id])->expectsQuestion($question, 'ASSIGN PLAN AUTHORITY '.$one->id.' REVISION 0')->assertSuccessful();
        $this->assertDatabaseHas('platform_plan_authority', ['primary_user_id' => $one->id, 'revision' => 1]);
        $this->artisan('platform:plan-authority', ['user' => $two->id])->assertFailed();
        $phrase = 'TRANSFER PLAN AUTHORITY '.$one->id.' TO '.$two->id.' REVISION 1';
        $this->artisan('platform:plan-authority', ['user' => $two->id, '--transfer' => true, '--previous-user' => $one->id, '--expected-revision' => 1, '--reason' => 'operator_recovery'])->expectsQuestion('After independent operator verification, type: '.$phrase, $phrase)->assertSuccessful();
        $this->assertDatabaseHas('platform_plan_authority', ['primary_user_id' => $two->id, 'revision' => 2]);
        $this->assertDatabaseHas('plan_events', ['action' => 'authority.transferred', 'actor_kind' => 'operator']);
        $this->assertGreaterThan(0, $one->fresh()->auth_session_version);
        $this->assertGreaterThan(0, $two->fresh()->auth_session_version);
    }

    private function publishCode(string $code): array
    {
        $row = collect($this->getJson('/api/platform/plans')->assertOk()->json('data'))->firstWhere('code', $code);
        $url = '/api/platform/plans/'.$row['id'].'/versions/'.$row['versions'][0]['id'];
        $preview = $this->postJson($url.'/previews', ['policy' => 'keep_existing'], $this->headers())->assertStatus(202)->json('data');

        return $this->postJson($url.'/publish', ['previewId' => $preview['id'], 'contentHash' => $preview['contentHash'], 'policy' => 'keep_existing'], $this->headers())->assertOk()->json('data');
    }

    public function test_policy_publish_checks_targets_preserves_workspace_state_and_is_idempotent(): void
    {
        $this->primary();
        $basic = $this->publishCode('basic');
        $studio = $this->publishCode('studio');
        $policy = $this->getJson('/api/platform/plan-settings')->assertOk()->json('data.0');
        $config = $policy['configuration'];
        $invalid = $config;
        $invalid['trialVersionId'] = $basic['id'];
        $this->postJson('/api/platform/plan-settings/drafts', $invalid, $this->headers())->assertUnprocessable();
        $created = $this->postJson('/api/platform/plan-settings/drafts', $config, $this->headers(key: 'policy-create'))->assertCreated()->json('data');
        $this->postJson('/api/platform/plan-settings/drafts', $config, $this->headers(key: 'policy-create'))->assertCreated()->assertJsonPath('data.id', $created['id']);
        $url = '/api/platform/plan-settings/drafts/'.$created['id'];
        $preview = $this->postJson($url.'/previews', ['policy' => 'keep_existing'], $this->headers())->assertStatus(202)->json('data');
        $body = ['previewId' => $preview['id'], 'contentHash' => $preview['contentHash'], 'policy' => 'keep_existing'];
        $this->postJson($url.'/publish', $body, $this->headers(key: 'policy-publish'))->assertOk()->assertJsonPath('data.state', 'published');
        $this->postJson($url.'/publish', $body, $this->headers(key: 'policy-publish'))->assertOk();
        $this->putJson($url, $config, $this->headers(2))->assertConflict();
        $this->getJson('/api/platform/plan-settings')->assertJsonPath('currentPolicyId', $created['id'])->assertJsonPath('rolloutMode', 'off');
        $this->assertDatabaseCount('workspace_subscriptions', 0);
        $this->assertDatabaseCount('owner_trial_claims', 0);
        $this->postJson('/api/platform/plans/'.$studio['planId'].'/archive', [], $this->headers(2))->assertConflict();
    }

    public function test_rule_copy_preview_and_confirmation_do_not_execute_workspace_transitions(): void
    {
        $this->primary();
        $basic = $this->publishCode('basic');
        $studio = $this->publishCode('studio');
        $rule = ['sourceVersionId' => $studio['id'], 'targetVersionId' => $basic['id'], 'initiator' => 'owner', 'approval' => 'manual', 'timing' => 'immediate', 'enabled' => false, 'overagePolicy' => ['active' => 'select_and_suspend', 'storage' => 'read_only']];
        $created = $this->postJson('/api/platform/plan-transition-rules', $rule, $this->headers())->assertCreated()->json('data.id');
        $this->patchJson('/api/platform/plan-transition-rules/'.$created, [...$rule, 'overagePolicy' => ['active' => 'block_growth', 'storage' => 'reject']], $this->headers())->assertUnprocessable();
        $draft = $this->postJson('/api/platform/plans/'.$studio['planId'].'/draft', [], $this->headers(2))->assertCreated()->json('data');
        $url = '/api/platform/plans/'.$studio['planId'].'/versions/'.$draft['id'];
        $preview = $this->postJson($url.'/previews', ['policy' => 'keep_existing'], $this->headers())->assertStatus(202)->json('data');
        $this->postJson($url.'/publish', ['previewId' => $preview['id'], 'contentHash' => $preview['contentHash'], 'policy' => 'keep_existing'], $this->headers())->assertOk();
        $copy = $this->postJson('/api/platform/plan-transition-rules/copy-previews', ['fromVersionId' => $studio['id'], 'toVersionId' => $draft['id']], $this->headers())->assertStatus(202)->json('data');
        $this->assertCount(1, $copy['summary']['candidates']);
        $body = ['previewId' => $copy['id'], 'contentHash' => $copy['contentHash']];
        $this->postJson('/api/platform/plan-transition-rules/copies', $body, $this->headers(key: 'copy-rules'))->assertOk();
        $this->postJson('/api/platform/plan-transition-rules/copies', $body, $this->headers(key: 'copy-rules'))->assertOk();
        $this->assertDatabaseCount('plan_transition_rules', 2);
        $this->assertDatabaseMissing('plan_transition_rules', ['enabled' => true]);
        $this->assertDatabaseCount('subscription_changes', 0);
        $this->assertDatabaseCount('workspace_execution_epochs', 0);
    }

    public function test_csrf_revoked_sessions_and_authority_changes_cannot_mutate_even_on_replay(): void
    {
        $actor = $this->primary();
        $this->app->bind(PreventRequestForgery::class, fn () => new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $row = $this->draft();
        $url = '/api/platform/plans/'.$row['id'].'/versions/'.$row['versions'][0]['id'].'/previews';
        $this->postJson($url, ['policy' => 'keep_existing'], $this->headers())->assertStatus(419);
        $token = $this->getJson('/api/csrf')->assertOk()->json('token');
        $this->withHeader('X-CSRF-TOKEN', $token)->postJson($url, ['policy' => 'keep_existing'], $this->headers(key: 'auth-replay'))->assertStatus(202);
        DB::table('platform_plan_authority')->where('id', 1)->update(['primary_user_id' => null, 'revision' => 2]);
        $this->postJson($url, ['policy' => 'keep_existing'], $this->headers(key: 'auth-replay'))->assertForbidden();
        DB::table('platform_plan_authority')->where('id', 1)->update(['primary_user_id' => $actor->id]);
        app(AccountProfile::class)->revokeSessions($actor);
        $this->getJson('/api/platform/plans')->assertUnauthorized();
    }

    public function test_audit_failure_rolls_back_http_creation_and_safe_errors_do_not_echo_payload(): void
    {
        $this->primary();
        $row = $this->draft();
        $config = $this->getJson('/api/platform/plans/'.$row['id'].'/versions/'.$row['versions'][0]['id'])->assertOk()->json('data.configuration');
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'plan_events')) {
                throw new \RuntimeException('Synthetic mandatory audit failure');
            }
        });
        $this->postJson('/api/platform/plans', ['code' => 'audit-rollback', ...$config], $this->headers())->assertStatus(500)->assertDontSee('Synthetic mandatory audit failure');
        $this->assertDatabaseMissing('plans', ['code' => 'audit-rollback']);
    }

    public function test_free_paid_only_values_are_rejected_and_metadata_archive_history_are_preserved(): void
    {
        $this->primary();
        $row = $this->draft();
        $config = $this->getJson('/api/platform/plans/'.$row['id'].'/versions/'.$row['versions'][0]['id'])->json('data.configuration');
        $invalid = $config;
        foreach ($invalid['entitlements'] as &$entry) {
            if ($entry['key'] === 'api.external.enabled') {
                $entry['value']['value'] = true;
            }
        }
        unset($entry);
        $this->postJson('/api/platform/plans', ['code' => 'bad-free', ...$invalid], $this->headers())->assertUnprocessable();
        $created = $this->postJson('/api/platform/plans', ['code' => 'unused-hidden', ...$config], $this->headers())->assertCreated()->json('data');
        $url = '/api/platform/plans/'.$created['planId'];
        $this->patchJson($url, ['visibility' => 'hidden', 'sortOrder' => 5], $this->headers())->assertOk()->assertJsonPath('data.visibility', 'hidden');
        $this->postJson($url.'/archive', [], $this->headers(2))->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson($url.'/unarchive', [], $this->headers(3))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->getJson($url.'/history')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/platform/plans?visibility=hidden&q=unused&sort=code')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unverified_primary_missing_mfa_and_unverified_operator_targets_are_rejected(): void
    {
        $actor = $this->primary();
        $actor->forceFill(['email_verified_at' => null])->save();
        $this->getJson('/api/platform/plans')->assertForbidden();
        $this->artisan('platform:plan-authority', ['user' => $actor->id, '--transfer' => true, '--previous-user' => $actor->id, '--expected-revision' => 1])->assertFailed();
        $actor->forceFill(['email_verified_at' => now(), 'two_factor_confirmed_at' => null])->save();
        $this->getJson('/api/platform/plans')->assertForbidden();
    }

    public function test_preview_expiry_hash_and_authority_revision_fail_closed(): void
    {
        $this->primary();
        $row = $this->draft();
        $url = '/api/platform/plans/'.$row['id'].'/versions/'.$row['versions'][0]['id'];
        $preview = $this->postJson($url.'/previews', ['policy' => 'keep_existing'], $this->headers())->assertStatus(202)->json('data');
        $body = ['previewId' => $preview['id'], 'contentHash' => str_repeat('0', 64), 'policy' => 'keep_existing'];
        $this->postJson($url.'/publish', $body, $this->headers())->assertConflict();
        $body['contentHash'] = $preview['contentHash'];
        DB::table('platform_plan_authority')->where('id', 1)->update(['revision' => 2]);
        $this->postJson($url.'/publish', $body, $this->headers())->assertConflict();
        DB::table('platform_plan_authority')->where('id', 1)->update(['revision' => 1]);
        $this->travel(901)->seconds();
        $actor = $this->app['auth']->user();
        $this->actingAs($actor);
        $this->postJson($url.'/publish', $body, $this->headers())->assertConflict();
        $this->assertDatabaseHas('plan_versions', ['uuid' => $row['versions'][0]['id'], 'state' => 'draft']);
    }

    public function test_new_draft_can_copy_a_selected_published_version_and_rejects_foreign_parent(): void
    {
        $this->primary();
        $studio = $this->publishCode('studio');
        $basic = $this->publishCode('basic');
        $url = '/api/platform/plans/'.$studio['planId'].'/draft';
        $this->postJson($url, ['sourceVersionId' => $basic['id']], $this->headers(2))->assertNotFound();
        $created = $this->postJson($url, ['sourceVersionId' => $studio['id']], $this->headers(2))->assertCreated()->json('data');
        $this->assertSame($studio['configuration'], $created['configuration']);
        $this->postJson($url, ['sourceVersionId' => $studio['id']], $this->headers(3))->assertConflict();
        $this->assertDatabaseCount('workspace_subscriptions', 0);
    }
}
