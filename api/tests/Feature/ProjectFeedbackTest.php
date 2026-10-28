<?php

namespace Tests\Feature;

use App\Mail\ProjectActivityMail;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectFeedback;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectFeedbacks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProjectFeedbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project, ?ProjectClient $client = null): void
    {
        $client ??= $project->clients()->first();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->app['auth']->forgetGuards();
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    private function root(Project $project): string
    {
        return '/api/client/project/'.$project->uuid.'/feedback';
    }

    public function test_completion_ignores_trash_and_never_counts_empty_or_partial_projects_as_done(): void
    {
        $project = Project::factory()->create();
        $service = app(ProjectFeedbacks::class);
        $this->assertFalse($service->eligible($project));
        $done = Task::factory()->for($project)->create(['status' => 'done']);
        $this->assertTrue($service->eligible($project));
        $unfinished = Task::factory()->for($project)->create(['status' => 'in_progress']);
        $this->assertFalse($service->eligible($project));
        $unfinished->delete();
        $this->assertTrue($service->eligible($project));
        $done->delete();
        $this->assertFalse($service->eligible($project));
    }

    public function test_only_assigned_team_can_request_once_and_state_survives_task_reopening(): void
    {
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $developer = User::factory()->memberOf($project->workspace)->create();
        $url = '/api/admin/projects/'.$project->uuid.'/feedback/request';
        $this->actingAs($owner)->postJson($url)->assertConflict();
        $task = Task::factory()->for($project)->create(['status' => 'done']);
        $this->actingAs($developer)->postJson($url)->assertNotFound();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk();
        $this->assertDatabaseCount('project_feedback', 1);
        $task->update(['status' => 'in_progress']);
        $this->postJson($url)->assertConflict();
        $task->update(['status' => 'done']);
        $this->postJson($url)->assertOk();
        $this->assertDatabaseCount('project_feedback', 1);
        $this->getJson('/api/admin/projects')->assertJsonPath('data.0.feedback.eligible', true)->assertJsonPath('data.0.feedback.requested', true);
    }

    public function test_auto_prompt_is_claimed_once_per_client_and_dismissal_is_durable(): void
    {
        $project = Project::factory()->create();
        Task::factory()->for($project)->create(['status' => 'done']);
        $other = ProjectClient::factory()->for($project)->create();
        $this->actingAs($this->ownerFor($project))->postJson('/api/admin/projects/'.$project->uuid.'/feedback/request')->assertOk();
        $this->client($project);
        $root = $this->root($project);
        $this->getJson($root)->assertOk()->assertJsonPath('shown', false);
        $this->postJson($root.'/invitation', ['action' => 'claim'])->assertOk()->assertJsonPath('autoOpen', true);
        $this->postJson($root.'/invitation', ['action' => 'claim'])->assertOk()->assertJsonPath('autoOpen', false);
        $this->postJson($root.'/invitation', ['action' => 'dismiss'])->assertOk()->assertJsonPath('dismissed', true);
        $this->getJson($root)->assertJsonPath('dismissed', true)->assertJsonPath('submitted', false);
        $this->client($project, $other);
        $this->getJson($root)->assertJsonPath('shown', false)->assertJsonPath('dismissed', false);
        $this->postJson($root.'/invitation', ['action' => 'claim'])->assertJsonPath('autoOpen', true);
    }

    public function test_feedback_is_server_scoped_unique_and_an_http_retry_cannot_edit_or_remail_it(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project, ['preferred_locale' => 'ru']);
        $task = Task::factory()->for($project)->create(['status' => 'done']);
        $this->client($project);
        $root = $this->root($project);
        $this->postJson($root, ['rating' => 5, 'text' => '<script>alert(1)</script> Спасибо!'])->assertOk();
        $feedback = ProjectFeedback::firstOrFail();
        $this->assertSame($project->workspace_id, $feedback->workspace_id);
        $this->assertSame($project->clients()->first()->id, $feedback->project_client_id);
        $this->postJson($root, ['rating' => 1, 'text' => 'Overwrite'])->assertOk();
        $this->assertSame(5, $feedback->fresh()->rating);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseCount('project_feedback', 1);
        Mail::assertSentCount(1);
        $mail = Mail::sent(ProjectActivityMail::class)->first();
        $this->assertTrue($mail->hasTo($owner->notificationEmail()));
        $this->assertStringContainsString('&lt;script&gt;', $mail->render());
        $this->assertStringNotContainsString('<script>', $mail->render());
        $this->assertStringContainsString('5/5', $mail->payload['explanation']);
        $task->update(['status' => 'new']);
        $this->postJson($root, ['rating' => 5])->assertOk();
        $task->update(['status' => 'done']);
        $this->postJson($root.'/invitation', ['action' => 'claim'])->assertJsonPath('autoOpen', false);
        $this->actingAs($owner)->postJson('/api/admin/projects/'.$project->uuid.'/feedback/request')->assertOk();
        $this->getJson('/api/admin/projects')->assertJsonPath('data.0.feedback.submitted', true);
        Mail::assertSentCount(1);
    }

    public function test_smtp_error_keeps_feedback_and_durable_mail_intent_and_retry_is_deduplicated(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'system@example.test']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $project = Project::factory()->create();
        $this->ownerFor($project);
        Task::factory()->for($project)->create(['status' => 'done']);
        $this->client($project);
        $this->postJson($this->root($project), ['rating' => 4])->assertOk();
        $this->assertDatabaseHas('project_feedback', ['rating' => 4]);
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'failed', 'error_code' => 'email_delivery_failed']);
        Mail::swap(new MailManager($this->app));
        Mail::fake();
        $this->travel(6)->minutes();
        $this->artisan('projects:feedback-mail')->assertSuccessful();
        $this->artisan('projects:feedback-mail')->assertSuccessful();
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'sent']);
        Mail::assertSentCount(1);
    }

    public function test_validation_ownership_expiry_and_privacy_are_enforced(): void
    {
        Mail::fake();
        $project = Project::factory()->create();
        $foreign = Project::factory()->create();
        $this->client($project);
        $root = $this->root($project);
        $this->postJson($root, ['rating' => 5])->assertConflict();
        Task::factory()->for($project)->create(['status' => 'done']);
        foreach ([[], ['rating' => 0], ['rating' => 6], ['rating' => 1, 'text' => str_repeat('a', 5001)], ['rating' => 3, 'project_client_id' => $foreign->clients()->first()->id]] as $body) {
            $this->postJson($root, $body)->assertUnprocessable();
        }
        $this->postJson($this->root($foreign), ['rating' => 5])->assertNotFound();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/feedback/request')->assertUnauthorized();
        $this->postJson($root, ['rating' => 3])->assertOk();
        $second = ProjectClient::factory()->for($project)->create();
        $this->client($project, $second);
        $this->getJson($root)->assertJsonPath('submitted', true)->assertJsonMissingPath('rating')->assertJsonMissingPath('text');
        $tokenId = session('client_access_token_id');
        DB::table('project_access_tokens')->where('id', $tokenId)->update(['expires_at' => now()->subMinute()]);
        $this->postJson($root, ['rating' => 5])->assertUnauthorized();
    }

    public function test_database_unique_constraint_prevents_duplicate_client_feedback(): void
    {
        $entry = ProjectFeedback::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('project_feedback')->insert(['workspace_id' => $entry->workspace_id, 'project_id' => $entry->project_id, 'project_client_id' => $entry->project_client_id]);
    }
}
