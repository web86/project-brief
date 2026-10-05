<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskPermissionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project): void
    {
        $token = $project->accessTokens()->create(['project_client_id' => $project->clients()->first()->id, 'token_hash' => hash('sha256', bin2hex(random_bytes(32)))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $project->clients()->first()->id, 'client_access_token_id' => $token->id]);
    }

    public function test_client_creates_and_edits_new_idea(): void
    {
        $project = Project::factory()->create();
        $this->client($project);
        $id = $this->postJson('/api/client/tasks', ['title' => 'Фотографии', 'location' => 'Главная', 'description' => 'Сделать крупнее', 'priority' => 'high'])->assertCreated()->assertJsonPath('data.status', 'new')->json('data.id');
        $this->assertDatabaseHas('tasks', ['uuid' => $id, 'project_id' => $project->id, 'status' => 'new']);
        $this->patchJson('/api/client/tasks/'.$id, ['title' => 'Крупные фотографии'])->assertOk()->assertJsonPath('data.title', 'Крупные фотографии');
        $this->assertDatabaseHas('task_history', ['event_type' => 'updated']);
    }

    public static function lockedStatuses(): array
    {
        return [['approved'], ['in_progress'], ['review'], ['done']];
    }

    #[DataProvider('lockedStatuses')]
    public function test_client_cannot_edit_locked_brief_with_403(string $status): void
    {
        $task = Task::factory()->create(['status' => $status]);
        $this->client($task->project);
        $this->patchJson('/api/client/tasks/'.$task->uuid, ['title' => 'Подмена'])->assertForbidden();
        $this->assertSame($task->title, $task->fresh()->title);
    }

    public static function protectedFields(): array
    {
        return [['estimateHours', 4], ['price', 99], ['developerNotes', 'secret'], ['status', 'done'], ['project_id', 999], ['clientApproved', true], ['history', []], ['estimate_hours', 5], ['developer_notes', 'secret']];
    }

    #[DataProvider('protectedFields')]
    public function test_client_cannot_write_protected_fields_with_422(string $field, mixed $value): void
    {
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->patchJson('/api/client/tasks/'.$task->uuid, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('new', $task->fresh()->status);
        $this->assertNull($task->fresh()->price);
    }

    public function test_client_never_receives_developer_fields_or_internal_history(): void
    {
        $task = Task::factory()->create(['estimate_hours' => 3, 'price' => 150, 'developer_notes' => 'secret product notes']);
        $this->client($task->project);
        $task->history()->create(['actor_type' => 'admin', 'event_type' => 'developer_notes', 'meta' => ['text' => 'internal secret'], 'created_at' => now()]);
        foreach (['/api/client/tasks', '/api/client/tasks/'.$task->uuid] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertStringNotContainsString('secret', $response->getContent());
            $this->assertStringNotContainsString('developerNotes', $response->getContent());
            $this->assertStringNotContainsString('estimateHours', $response->getContent());
            $this->assertStringNotContainsString('price', $response->getContent());
        }
    }

    public function test_cross_project_reads_edits_and_approval_return_404(): void
    {
        $own = Project::factory()->create();
        $other = Task::factory()->create();
        $this->client($own);
        $this->getJson('/api/client/tasks/'.$other->uuid)->assertNotFound();
        $this->patchJson('/api/client/tasks/'.$other->uuid, ['title' => 'Подмена'])->assertNotFound();
        $this->postJson('/api/client/tasks/'.$other->uuid.'/approve')->assertNotFound();
        $this->getJson('/api/client/tasks')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_approval_is_idempotent_and_does_not_change_status(): void
    {
        $this->freezeTime();
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/approve')->assertOk()->assertJsonPath('data.clientApproved', true)->assertJsonPath('data.status', 'new');
        $this->postJson('/api/client/tasks/'.$task->uuid.'/approve')->assertOk();
        $this->assertNotNull($task->fresh()->client_approved_at);
        $this->assertDatabaseCount('task_history', 1);
        $this->assertDatabaseHas('task_history', ['event_type' => 'approved']);
    }

    public function test_admin_updates_workflow_and_audit_without_leaking_notes_into_history(): void
    {
        $task = Task::factory()->create();
        $this->actingAs(User::factory()->create());
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'in_progress', 'estimateHours' => 3, 'price' => 150, 'developerNotes' => 'private notes'])->assertOk()->assertJsonPath('data.developerNotes', 'private notes');
        $this->assertDatabaseHas('tasks', ['uuid' => $task->uuid, 'status' => 'in_progress', 'estimate_hours' => 3, 'price' => 150]);
        $this->assertDatabaseHas('task_history', ['event_type' => 'status', 'old_value' => 'new', 'new_value' => 'in_progress']);
        $this->assertDatabaseCount('task_history', 4);
        $this->assertStringNotContainsString('private notes', $task->history()->get()->toJson());
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'invalid'])->assertUnprocessable();
    }

    public function test_client_can_edit_clarification_and_rejected_creation_cannot_set_internal_fields(): void
    {
        $task = Task::factory()->create(['status' => 'clarification']);
        $this->client($task->project);
        $this->patchJson('/api/client/tasks/'.$task->uuid, ['description' => 'Уточнённое описание'])->assertOk()->assertJsonPath('data.description', 'Уточнённое описание');
        $this->postJson('/api/client/tasks', ['title' => 'Подмена', 'location' => 'Главная', 'description' => 'Описание', 'priority' => 'normal', 'status' => 'done', 'price' => 99])->assertUnprocessable()->assertJsonValidationErrors(['status', 'price']);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_estimate_and_price_audit_retain_previous_values(): void
    {
        $task = Task::factory()->create(['estimate_hours' => 3, 'price' => 150]);
        $this->actingAs(User::factory()->create());
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['estimateHours' => 5, 'price' => 200])->assertOk();
        $this->assertDatabaseHas('task_history', ['event_type' => 'estimate', 'old_value' => '3', 'new_value' => '5']);
        $this->assertDatabaseHas('task_history', ['event_type' => 'price', 'old_value' => '150', 'new_value' => '200']);
    }
}
