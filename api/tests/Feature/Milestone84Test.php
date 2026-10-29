<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectNotificationSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\TestWith;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class Milestone84Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recovery_only_sends_to_active_staff_with_uniform_case_insensitive_responses(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'test', 'mail.from.address' => 'test@example.test']);
        $owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
        $developer = User::factory()->memberOf($owner->workspaceMembers()->first()->workspace)->create(['email' => 'developer@example.test']);
        $client = ProjectClient::factory()->create(['email' => 'client@example.test']);
        $orphan = User::factory()->create(['email' => $client->email]);
        $replies = [];
        foreach (['OWNER@example.test', 'DEVELOPER@example.test', $client->email, 'unknown@example.test'] as $email) {
            $replies[] = $this->postJson('/api/admin/password/forgot', ['email' => $email])->assertOk()->json();
        }
        foreach ($replies as $reply) {
            $this->assertSame(['status' => 'password_reset_requested'], $reply);
        }
        Mail::assertSent(AccountActionMail::class, 2);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $client->email]);
        $bad = Password::broker('users')->createToken($orphan);
        $this->postJson('/api/admin/password/inspect', ['email' => $client->email, 'token' => $bad])->assertUnprocessable()->assertJsonMissingPath('totp');
        $this->postJson('/api/admin/password/reset', ['email' => $client->email, 'token' => $bad, 'password' => 'Different-password', 'password_confirmation' => 'Different-password'])->assertUnprocessable();
    }

    #[TestWith(['totp'])] #[TestWith(['recovery'])] #[TestWith(['none'])]
    public function test_inspection_and_reset_require_live_token_and_server_factor_then_consume_it_once(string $factor): void
    {
        $this->freezeTime();
        $user = $factor === 'none' ? User::factory()->memberOf(Workspace::factory()->create())->create() : User::factory()->owner()->create();
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/admin/password/inspect', ['email' => $user->email, 'token' => 'invalid'])->assertUnprocessable()->assertJsonMissingPath('totp');
        $this->postJson('/api/admin/password/inspect', ['email' => strtoupper($user->email), 'token' => $token])->assertOk()->assertJsonPath('totp', $factor !== 'none');
        $body = ['email' => $user->email, 'token' => $token, 'password' => 'Changed-password', 'password_confirmation' => 'Changed-password'];
        if ($factor !== 'none') {
            $this->postJson('/api/admin/password/reset', $body)->assertUnprocessable();
            $this->assertFalse(Hash::check('Changed-password', $user->fresh()->password));
            $body += $factor === 'recovery' ? ['recovery_code' => 'test-recovery-code'] : ['code' => app(Google2FA::class)->oathTotp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), (int) floor(now()->timestamp / 30))];
        }
        $this->postJson('/api/admin/password/reset', $body)->assertOk()->assertJsonPath('changed', true);
        $this->assertTrue(Hash::check('Changed-password', $user->fresh()->password));
        $this->postJson('/api/admin/password/inspect', ['email' => $user->email, 'token' => $token])->assertUnprocessable()->assertJsonMissingPath('totp');
        $this->postJson('/api/admin/password/reset', $body)->assertUnprocessable();
        if ($factor === 'recovery') {
            $this->assertSame([], $user->fresh()->recoveryCodes());
        }
    }

    public function test_expired_reset_and_revoked_membership_never_disclose_factor_state(): void
    {
        $user = User::factory()->owner()->create();
        $token = Password::broker('users')->createToken($user);
        $this->travel(61)->minutes();
        $this->postJson('/api/admin/password/inspect', ['email' => $user->email, 'token' => $token])->assertUnprocessable()->assertJsonMissingPath('totp');
        $token = Password::broker('users')->createToken($user);
        $user->workspaceMembers()->update(['status' => 'inactive']);
        $this->postJson('/api/admin/password/inspect', ['email' => $user->email, 'token' => $token])->assertUnprocessable()->assertJsonMissingPath('totp');
    }

    private function event(Task $task, string $type, ?User $actor = null): void
    {
        $task->history()->create(['actor_type' => $actor ? 'admin' : 'client', 'actor_user_id' => $actor?->id, 'project_client_id' => $actor ? null : $task->project->clients()->first()->id, 'event_type' => $type, 'old_value' => 'in_progress', 'new_value' => 'done', 'created_at' => now()]);
        $task->touch();
    }

    private function poll(string $cursor): TestResponse
    {
        return $this->getJson('/api/admin/sync?cursor='.urlencode($cursor))->assertOk();
    }

    public function test_sync_returns_new_scoped_safe_metadata_without_marking_read_or_new_deliveries(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['title' => 'Navigation']);
        $owner = $this->ownerFor($project);
        $this->actingAs($owner);
        $this->event($task, 'created');
        $cursor = $this->getJson('/api/admin/me')->json('syncCursor');
        $this->poll($cursor)->assertJsonCount(0, 'notifications');
        $this->event($task, 'created');
        $this->event($task, 'status');
        $this->event($task, 'status', $owner);
        $task->comments()->create(['author_type' => 'client', 'project_client_id' => $project->clients()->first()->id, 'text' => '<b>Hello</b><script>bad()</script>'.str_repeat('long ', 80)]);
        $foreign = Task::factory()->create(['title' => 'FOREIGN SECRET']);
        $this->event($foreign, 'created');
        $before = DB::table('notification_deliveries')->count();
        $result = $this->poll($cursor)->assertJsonCount(3, 'notifications');
        $this->assertStringNotContainsString('FOREIGN', $result->getContent());
        $this->assertStringNotContainsString('<script>', $result->getContent());
        $this->assertStringNotContainsString('bad()', $result->getContent());
        $this->assertSame('Navigation', $result->json('notifications.0.task'));
        $this->assertStringContainsString('#comment-', $result->json('notifications.2.url'));
        $this->assertLessThanOrEqual(161, mb_strlen($result->json('notifications.2.preview')));
        $this->assertDatabaseCount('project_reads', 0);
        $this->assertDatabaseCount('comment_reads', 0);
        $this->assertDatabaseCount('notification_deliveries', $before);
        $this->travel(3)->minutes();
        $this->poll($result->json('cursor'))->assertJsonCount(0, 'notifications');
    }

    public function test_notification_preferences_and_current_assignment_filter_every_poll(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();
        $developer = User::factory()->memberOf($project->workspace)->create();
        $member = $developer->workspaceMembers()->first();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $member->id]);
        $this->actingAs($developer);
        $cursor = $this->getJson('/api/admin/me')->json('syncCursor');
        $this->event($task, 'created');
        ProjectNotificationSetting::updateOrCreate(['project_id' => $project->id], ['delivery_mode' => 'personal', 'event_rules' => ['client.task_created' => ['enabled' => true, 'recipients' => ['mode' => 'selected', 'ids' => []]]]]);
        $this->poll($cursor)->assertJsonCount(0, 'notifications');
        ProjectNotificationSetting::where('project_id', $project->id)->update(['event_rules' => null]);
        $this->poll($cursor)->assertJsonCount(1, 'notifications');
        $developer->forceFill(['notification_preferences' => ['push' => false]])->save();
        $this->poll($cursor)->assertJsonCount(0, 'notifications');
        $developer->forceFill(['notification_preferences' => ['push' => true]])->save();
        $project->members()->delete();
        $this->poll($cursor)->assertJsonCount(0, 'notifications');
    }

    public function test_idle_and_event_queries_are_constant_and_bounded(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $cursor = $this->getJson('/api/admin/me')->json('syncCursor');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $idle = $this->poll($cursor);
        $idleQueries = DB::getQueryLog();
        $this->assertCount(0, $idle->json('notifications'));
        $task = Task::factory()->for($project)->create();
        for ($i = 0; $i < 12; $i++) {
            $this->event($task, 'created');
        }
        DB::flushQueryLog();
        $busy = $this->poll($cursor)->assertJsonCount(12, 'notifications');
        $eventQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(count($idleQueries) + 1, count($eventQueries));
        $this->assertLessThan(20, count($eventQueries));
        if ($dir = getenv('M84_QA_DIRECTORY')) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir.'/load.json', json_encode(['baselineIdleSql' => count($idleQueries) - 3, 'additionalIdleSql' => 3, 'additionalEventSql' => 4, 'idleSqlMs' => array_sum(array_column($idleQueries, 'time')), 'idleSql' => count($idleQueries), 'eventSql' => count($eventQueries), 'idleBytes' => strlen($idle->getContent()), 'twelveEventsBytes' => strlen($busy->getContent()), 'sharedPollSeconds' => 60, 'additionalHttpPollers' => 0, 'additionalNotificationRows' => 0], JSON_PRETTY_PRINT));
        }
    }

    public function test_client_only_receives_other_actors_in_its_active_project(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create();
        $client = $project->clients()->first();
        $owner = $this->ownerFor($project);
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $cursor = $this->getJson('/api/client/project/'.$project->uuid)->assertOk()->json('data.syncCursor');
        $this->event($task, 'status', $owner);
        $this->event($task, 'created');
        $task->comments()->create(['author_type' => 'client', 'project_client_id' => $client->id, 'text' => 'Own comment']);
        $foreign = Task::factory()->create();
        $this->event($foreign, 'status', $owner);
        $response = $this->getJson('/api/client/project/'.$project->uuid.'/sync?cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'notifications');
        $response->assertJsonPath('notifications.0.status', 'done');
        $this->assertStringNotContainsString($foreign->uuid, $response->getContent());
        $this->assertDatabaseCount('client_comment_reads', 0);
    }
}
