<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectStructure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProjectStructureTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_status_location_and_client_approval_never_change_canonical_numbers(): void
    {
        $project = Project::factory()->create(['sections' => ['Главная', 'Каталог', 'Карточка товара', 'Контакты', 'Общее']]);
        $task = Task::factory()->for($project)->create(['section' => 'Главная']);
        $number = ProjectStructure::number($task->fresh());
        $this->actingAs(User::factory()->create());
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['status' => 'done', 'location' => 'https://example.com/another-place'])->assertOk()->assertJsonPath('data.displayNumber', $number);
        $this->getJson('/api/admin/projects/'.$project->uuid.'/tasks')->assertOk()->assertJsonPath('data.0.displayNumber', $number);
        $this->assertSame($task->position, $task->fresh()->position);
        $this->assertSame($task->project_section_id, $task->fresh()->project_section_id);
    }

    public function test_create_reorder_move_and_section_reorder_normalize_positions_and_record_history(): void
    {
        $project = Project::factory()->create(['sections' => ['Главная', 'Каталог', 'Карточка товара', 'Контакты', 'Общее']]);
        $main = $project->briefSections()->where('name', 'Главная')->first();
        $catalog = $project->briefSections()->where('name', 'Каталог')->first();
        $first = Task::factory()->for($project)->create(['section' => 'Главная']);
        $second = Task::factory()->for($project)->create(['section' => 'Главная']);
        Task::factory()->for($project)->create(['section' => 'Каталог']);
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/admin/tasks/'.$second->uuid.'/reorder', ['direction' => 'up'])->assertOk()->assertJsonPath('data.displayNumber', '1.1');
        $this->assertSame(2, $first->fresh()->position);
        $this->postJson('/api/admin/tasks/'.$second->uuid.'/move', ['sectionId' => $catalog->uuid])->assertOk()->assertJsonPath('data.displayNumber', '2.2');
        $this->assertSame([1], $main->tasks()->orderBy('position')->pluck('position')->all());
        $this->assertSame([1, 2], $catalog->tasks()->orderBy('position')->pluck('position')->all());
        $this->assertDatabaseHas('task_history', ['task_id' => $second->id, 'event_type' => 'section_moved', 'old_value' => '1.1', 'new_value' => '2.2']);
        $this->patchJson('/api/admin/projects/'.$project->uuid.'/sections/'.$catalog->uuid, ['direction' => 'up'])->assertOk();
        $this->getJson('/api/admin/tasks/'.$second->uuid)->assertOk()->assertJsonPath('data.displayNumber', '1.2');
        $this->postJson('/api/admin/projects/'.$project->uuid.'/tasks', ['title' => 'New', 'location' => 'Mobile menu', 'description' => 'Describe', 'priority' => 'normal'])->assertCreated()->assertJsonPath('data.section.name', 'Общее')->assertJsonPath('data.position', 1);
        $this->postJson('/api/admin/projects/'.$project->uuid.'/tasks', ['title' => 'Next', 'location' => 'https://example.com', 'description' => 'Describe', 'priority' => 'normal'])->assertCreated()->assertJsonPath('data.position', 2);
    }

    public function test_section_management_is_scoped_and_nonempty_sections_cannot_be_deleted(): void
    {
        $project = Project::factory()->create(['sections' => ['Главная', 'Каталог', 'Карточка товара', 'Контакты', 'Общее']]);
        $task = Task::factory()->for($project)->create();
        $other = Project::factory()->create()->briefSections()->first();
        $this->actingAs(User::factory()->create());
        $root = '/api/admin/projects/'.$project->uuid.'/sections';
        $this->deleteJson($root.'/'.$task->projectSection->uuid)->assertUnprocessable()->assertJsonValidationErrors('section');
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->patchJson($root.'/'.$other->uuid, ['name' => 'Stolen'])->assertNotFound();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/move', ['sectionId' => $other->uuid])->assertNotFound();
        $created = $this->postJson($root, ['name' => 'New section'])->assertCreated()->json('data');
        $section = end($created);
        $this->assertSame(6, $section['position']);
        $this->patchJson($root.'/'.$section['id'], ['name' => 'Renamed'])->assertOk();
        $this->deleteJson($root.'/'.$section['id'])->assertOk();
        $this->assertSame([1, 2, 3, 4, 5], $project->briefSections()->pluck('position')->all());
    }
}
