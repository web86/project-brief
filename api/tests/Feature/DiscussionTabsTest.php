<?php

namespace Tests\Feature;

use App\Events\ProjectNotificationRequested;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiscussionTabsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(Project $project, string $role): string
    {
        if ($role === 'client') {
            $client = $project->clients()->first();
            $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
            $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);

            return '/api/client/project/'.$project->uuid.'/discussions';
        }
        $user = $role === 'owner' ? $this->ownerFor($project) : User::factory()->memberOf($project->workspace)->create();
        if ($role === 'admin') {
            $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $user->workspaceMembers()->first()->id]);
        }
        $this->actingAs($user);

        return '/api/admin/projects/'.$project->uuid.'/discussions';
    }

    public static function personas(): array
    {
        return [['owner'], ['admin'], ['client']];
    }

    #[DataProvider('personas')]
    public function test_counts_cover_all_pages_and_marking_one_tab_preserves_the_other(string $role): void
    {
        Event::fake([ProjectNotificationRequested::class]);
        $task = Task::factory()->create(['developer_notes' => 'PRIVATE_NOTES']);
        $project = $task->project;
        $root = $this->actor($project, $role);
        for ($i = 0; $i < 32; $i++) {
            Comment::create(['project_id' => $project->id, 'author_type' => 'admin', 'text' => 'General '.$i]);
        }
        for ($i = 0; $i < 29; $i++) {
            $task->comments()->create(['author_type' => 'admin', 'text' => 'Task '.$i]);
        }
        $first = $this->getJson($root.'?kind=general')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('unreadCount', 61)->assertJsonPath('unreadCounts', ['general' => 32, 'tasks' => 29]);
        $this->getJson($root.'?kind=tasks')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('unreadCounts.tasks', 29)->assertDontSee('PRIVATE_NOTES');
        $this->assertDatabaseCount('comment_reads', 0);
        $this->assertDatabaseCount('client_comment_reads', 0);
        $this->getJson($root.'?kind=general&cursor='.urlencode($first->json('nextCursor')))->assertJsonCount(7, 'data')->assertJsonPath('unreadCounts.general', 32);
        $this->postJson($root.'/read', ['ids' => [$first->json('data.0.id')]])->assertOk()->assertJsonPath('unreadCounts', ['general' => 31, 'tasks' => 29])->assertJsonPath('unreadCount', 60);
        $this->postJson($root.'/read', ['all' => true, 'kind' => 'general'])->assertOk()->assertJsonPath('unreadCount', 29)->assertJsonPath('unreadCounts', ['general' => 0, 'tasks' => 29]);
        $base = $role === 'client' ? '/api/client/project/' : '/api/admin/projects/';
        $this->getJson($base.$project->uuid)->assertJsonPath('data.unreadCommentCount', 29);
        $this->postJson($root, ['text' => 'My own new comment'])->assertCreated();
        $this->getJson($root.'?kind=general')->assertJsonPath('unreadCounts.general', 0);
        $this->postJson($root.'/read', ['all' => true, 'kind' => 'tasks'])->assertOk()->assertJsonPath('unreadCounts', ['general' => 0, 'tasks' => 0]);
        Comment::create(['project_id' => $project->id, 'author_type' => 'admin', 'text' => 'New general']);
        $task->comments()->create(['author_type' => 'admin', 'text' => 'New task']);
        $this->postJson($root.'/read', ['all' => true])->assertOk()->assertJsonPath('unreadCount', 0);
    }

    #[DataProvider('personas')]
    public function test_deep_link_loads_a_scoped_chronological_window_without_marking_any_comments(string $role): void
    {
        $task = Task::factory()->create();
        $root = $this->actor($task->project, $role);
        foreach (['general', 'tasks'] as $kind) {
            $comments = [];
            for ($i = 0; $i < 65; $i++) {
                $comments[] = Comment::create(['project_id' => $kind === 'general' ? $task->project_id : null, 'task_id' => $kind === 'tasks' ? $task->id : null, 'author_type' => 'admin', 'text' => $kind.' '.$i, 'created_at' => now()->subMinutes(65 - $i)]);
            }
            $target = $comments[27];
            $first = $this->getJson($root.'?kind='.$kind)->assertOk()->assertJsonCount(25, 'data');
            $this->assertNotContains($target->uuid, array_column($first->json('data'), 'id'));
            $window = $this->getJson($root.'?comment='.$target->uuid)->assertOk()->assertJsonPath('kind', $kind)->assertJsonPath('data.0.id', $target->uuid)->assertJsonCount(25, 'data');
            $this->getJson($root.'?kind='.$kind.'&cursor='.urlencode($window->json('nextCursor')))->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $comments[2]->uuid);
            $this->getJson($root.'?comment='.$target->uuid.'&cursor='.urlencode($window->json('nextCursor')))->assertUnprocessable();
        }
        $this->assertDatabaseCount('comment_reads', 0);
        $this->assertDatabaseCount('client_comment_reads', 0);
        $foreign = Task::factory()->create();
        $comment = $foreign->comments()->create(['author_type' => 'admin', 'text' => 'FOREIGN_COMMENT']);
        $this->getJson($root.'?comment='.$comment->uuid)->assertNotFound()->assertDontSee('FOREIGN_COMMENT');
        $this->postJson($root.'/read', ['ids' => [$comment->uuid], 'kind' => 'tasks'])->assertNotFound();
        $this->getJson($root.'?comment=invalid')->assertUnprocessable();
        $this->postJson($root.'/read', ['all' => true, 'kind' => 'invalid'])->assertUnprocessable();
        $own = $task->comments()->first();
        $this->postJson($root.'/read', ['ids' => [$own->uuid], 'kind' => 'general'])->assertNotFound();
        $task->delete();
        $this->getJson($root.'?comment='.$own->uuid)->assertNotFound();
    }
}
