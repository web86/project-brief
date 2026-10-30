<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationPayload;
use App\Services\TaskAudit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientTaskStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project): void
    {
        $client = $project->clients()->firstOrFail();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'm94-only')]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
    }

    public static function statuses(): array
    {
        return array_map(fn (string $status): array => [$status], array_keys(TaskAudit::STATUSES));
    }

    #[DataProvider('statuses')]
    public function test_client_transition_matrix_is_checked_against_locked_server_state(string $from): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        $project = Project::factory()->create();
        $this->client($project);
        foreach (array_keys(TaskAudit::STATUSES) as $to) {
            foreach ([false, true] as $approved) {
                $task = Task::factory()->for($project)->create(['status' => $from, 'client_approved' => $approved]);
                $url = '/api/client/tasks/'.$task->uuid;
                $version = $this->getJson($url)->assertOk()->json('data.editVersion');
                $response = $this->patchJson($url, ['status' => $to, 'editVersion' => $version]);
                if ($from === 'review' && in_array($to, ['approved', 'done'], true)) {
                    $response->assertOk()->assertJsonPath('data.status', $to)->assertJsonPath('data.clientApproved', $approved);
                    $event = $task->history()->firstOrFail();
                    $this->assertSame('client', $event->actor_type);
                    $this->assertSame($from, $event->old_value);
                    $this->assertSame($to, $event->new_value);
                    $this->assertSame('status', $event->event_type);
                    Event::assertDispatched(ProjectNotificationRequested::class, fn ($event) => $event->event === 'client.task_status_changed' && $event->taskId === $task->id);
                    foreach (['ru', 'en', 'tr', 'zh-CN'] as $locale) {
                        $payload = NotificationPayload::make('client.task_status_changed', $task->fresh(), 'Client', $locale, true, (string) $event->id);
                        $this->assertStringNotContainsString('notifications.', $payload['heading']);
                        $this->assertStringContainsString(' → ', $payload['message']);
                        $this->assertStringContainsString('/admin/projects/', $payload['url']);
                    }
                } else {
                    $response->assertForbidden();
                    $this->assertSame($from, $task->fresh()->status);
                    $this->assertSame(0, $task->history()->count());
                }
            }
        }
    }

    public function test_client_status_requires_version_rejects_mixed_fields_and_preserves_regular_approval(): void
    {
        $task = Task::factory()->create(['status' => 'review', 'client_approved' => false]);
        $this->client($task->project);
        $url = '/api/client/tasks/'.$task->uuid;
        $version = $this->getJson($url)->json('data.editVersion');
        $this->patchJson($url, ['status' => 'done'])->assertUnprocessable();
        foreach (['description' => 'Injected', 'developerNotes' => 'Injected', 'clientApproved' => true, 'title' => 'Injected'] as $field => $value) {
            $this->patchJson($url, ['status' => 'done', 'editVersion' => $version, $field => $value])->assertUnprocessable();
        }
        $this->postJson('/api/client/tasks', ['title' => 'Injected', 'location' => 'Main', 'description' => 'Description', 'priority' => 'normal', 'status' => 'done'])->assertUnprocessable();
        $this->postJson($url.'/approve')->assertOk()->assertJsonPath('data.status', 'review')->assertJsonPath('data.clientApproved', true);
        $this->assertSame('review', $task->fresh()->status);
    }

    public function test_stale_replay_and_competing_requests_do_not_overwrite_a_new_status(): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        $task = Task::factory()->create(['status' => 'review']);
        $this->client($task->project);
        $url = '/api/client/tasks/'.$task->uuid;
        $version = $this->getJson($url)->json('data.editVersion');
        $task->update(['status' => 'in_progress']);
        $this->patchJson($url, ['status' => 'done', 'editVersion' => $version])->assertConflict()->assertJsonPath('code', 'task_conflict');
        $task->update(['status' => 'review']);
        $version = $this->getJson($url)->json('data.editVersion');
        $this->patchJson($url, ['status' => 'done', 'editVersion' => $version])->assertOk();
        foreach (['done', 'approved'] as $to) {
            $this->patchJson($url, ['status' => $to, 'editVersion' => $version])->assertConflict();
        }
        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame(1, $task->history()->where('event_type', 'status')->count());
        Event::assertDispatchedTimes(ProjectNotificationRequested::class, 1);
    }

    public function test_foreign_revoked_and_admin_endpoint_bypasses_are_denied(): void
    {
        $task = Task::factory()->create(['status' => 'review']);
        $foreign = Task::factory()->create(['status' => 'review']);
        $this->client($task->project);
        $this->patchJson('/api/client/tasks/'.$foreign->uuid, ['status' => 'done', 'editVersion' => $foreign->editVersion(false)])->assertNotFound();
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'done'])->assertUnauthorized();
        $task->project->accessTokens()->update(['revoked_at' => now()]);
        $this->patchJson('/api/client/tasks/'.$task->uuid, ['status' => 'done', 'editVersion' => $task->editVersion(false)])->assertUnauthorized();
        $this->assertSame('review', $task->fresh()->status);
    }

    public function test_owners_and_assigned_developers_keep_all_existing_transitions(): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        $project = Project::factory()->create();
        $owner = $this->ownerFor($project);
        $developer = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $developer->workspaceMembers()->first()->id]);
        foreach ([$owner, $developer] as $user) {
            $this->actingAs($user);
            foreach (array_keys(TaskAudit::STATUSES) as $from) {
                foreach (array_keys(TaskAudit::STATUSES) as $to) {
                    $task = Task::factory()->for($project)->create(['status' => $from]);
                    $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => $to])->assertOk()->assertJsonPath('data.status', $to);
                }
            }
        }
    }
}
