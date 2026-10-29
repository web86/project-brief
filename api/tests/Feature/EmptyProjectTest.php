<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EmptyProjectTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['ru', 'Первый раздел'])]
    #[TestWith(['en', 'First section'])]
    #[TestWith(['tr', 'İlk bölüm'])]
    #[TestWith(['zh-CN', '第一个分区'])]
    public function test_client_creates_first_section_with_task_and_can_reuse_or_add_sections(string $locale, string $name): void
    {
        $project = Project::factory()->create();
        $project->briefSections()->delete();
        $foreign = Task::factory()->create();
        $before = $foreign->fresh()->getAttributes();
        $client = $project->clients()->firstOrFail();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $body = ['title' => 'First task', 'section' => $name, 'location' => $name, 'description' => 'Client requirements', 'priority' => 'normal'];

        $this->withHeader('Accept-Language', $locale)->postJson('/api/client/tasks', $body)->assertCreated()
            ->assertJsonPath('data.section.name', $name)->assertJsonPath('data.displayNumber', '1.1');
        $this->postJson('/api/client/tasks', [...$body, 'title' => 'Second task'])->assertCreated()->assertJsonPath('data.displayNumber', '1.2');
        $this->assertSame(1, $project->briefSections()->count());
        $this->postJson('/api/client/tasks', [...$body, 'title' => 'Third task', 'section' => $name.' 2'])->assertCreated()->assertJsonPath('data.displayNumber', '2.1');
        $this->getJson('/api/client/project/'.$project->uuid)->assertOk()->assertJsonCount(2, 'data.sections');
        $this->assertSame(3, $project->tasks()->count());
        $this->assertDatabaseHas('task_history', ['task_id' => $project->tasks()->first()->id, 'event_type' => 'created', 'project_client_id' => $client->id]);
        $this->assertSame($before, $foreign->fresh()->getAttributes());
        $this->getJson('/api/client/project/'.$foreign->project->uuid)->assertNotFound();
    }

    public function test_invalid_task_and_revoked_client_cannot_leave_a_first_section_behind(): void
    {
        $project = Project::factory()->create();
        $project->briefSections()->delete();
        $client = $project->clients()->firstOrFail();
        $token = $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', random_bytes(32))]);
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $client->id, 'client_access_token_id' => $token->id]);
        $body = ['title' => 'First task', 'section' => 'First section', 'location' => 'Home', 'priority' => 'normal'];

        $this->postJson('/api/client/tasks', $body)->assertUnprocessable();
        $client->update(['active' => false]);
        $this->postJson('/api/client/tasks', [...$body, 'description' => 'Requirements'])->assertUnauthorized();
        $this->assertSame(0, $project->briefSections()->count());
        $this->assertSame(0, $project->tasks()->count());
    }

    public function test_creation_is_empty_and_existing_structure_and_tasks_are_preserved(): void
    {
        $existing = Project::factory()->create();
        $task = Task::factory()->for($existing)->create();
        $before = $existing->briefSections()->get()->toArray();
        $beforeTask = $task->fresh()->getAttributes();
        $this->actingAs($this->ownerFor($existing));
        $response = $this->postJson('/api/admin/projects', ['title' => 'Empty / Çığ / 中文', 'currency' => 'USD'])->assertCreated()->assertJsonPath('data.sections', []);
        $uuid = $response->json('data.id');
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(0, $project->tasks()->count());
        $this->getJson('/api/admin/projects/'.$uuid.'/sections')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/projects/'.$uuid.'/tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/projects/'.$uuid.'/discussions')->assertOk();
        foreach (['export-data' => 'pdf', 'export' => 'docx'] as $endpoint => $format) {
            $this->postJson('/api/admin/projects/'.$uuid.'/'.$endpoint, ['format' => $format, 'locale' => 'en', 'scope' => 'all'])->assertUnprocessable()->assertJsonPath('errors.scope.0', trans('exports.empty', [], 'en'));
        }
        $this->postJson('/api/admin/projects/'.$uuid.'/sections', ['name' => 'First / Çığ / 中文'])->assertCreated()->assertJsonPath('data.0.position', 1)->assertJsonPath('data.0.name', 'First / Çığ / 中文');
        $this->assertSame($before, $existing->briefSections()->get()->toArray());
        $this->assertSame($beforeTask, $task->fresh()->getAttributes());
    }

    public function test_creation_cannot_seed_sections_and_existing_project_updates_cannot_replace_them(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->postJson('/api/admin/projects', ['title' => 'Unsafe seed', 'sections' => ['Home']])->assertUnprocessable()->assertJsonValidationErrors('sections');
        $this->assertDatabaseCount('projects', 0);
        $uuid = $this->postJson('/api/admin/projects', ['title' => 'Empty'])->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/projects/'.$uuid, ['sections' => ['Replacement']])->assertUnprocessable();
        $this->assertDatabaseCount('project_sections', 0);
    }
}
