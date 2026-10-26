<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EmptyProjectTest extends TestCase
{
    use LazilyRefreshDatabase;

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
