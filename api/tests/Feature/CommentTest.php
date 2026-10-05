<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function client(Project $project): void
    {
        $token = $project->accessTokens()->create(['token_hash' => hash('sha256', bin2hex(random_bytes(32)))]);
        $this->withSession(['client_project_id' => $project->id, 'client_access_token_id' => $token->id]);
    }

    public function test_admin_comment_author_and_history_are_server_derived(): void
    {
        $task = Task::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/admin/tasks/'.$task->uuid.'/comments', ['text' => 'Примерно на 20%?'])->assertOk()->assertJsonPath('data.comments.0.author', 'developer');
        $this->assertDatabaseHas('comments', ['task_id' => $task->id, 'author_type' => 'admin', 'author_user_id' => $user->id]);
        $this->assertDatabaseHas('task_history', ['event_type' => 'comment', 'actor_type' => 'admin']);
    }

    public function test_client_comment_author_is_server_derived_and_cannot_be_forged(): void
    {
        $task = Task::factory()->create();
        $this->client($task->project);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Да', 'author_type' => 'admin'])->assertUnprocessable();
        $this->assertDatabaseCount('comments', 0);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Да, примерно.'])->assertOk()->assertJsonPath('data.comments.0.author', 'client');
        $this->assertDatabaseHas('comments', ['task_id' => $task->id, 'author_type' => 'client', 'author_user_id' => null]);
    }

    public function test_client_cannot_comment_on_another_project_with_404(): void
    {
        $task = Task::factory()->create();
        $this->client(Project::factory()->create());
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Подмена'])->assertNotFound();
        $this->assertDatabaseCount('comments',0);
    }
}
