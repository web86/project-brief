<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_and_updates_project(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->postJson('/api/admin/projects', ['title' => 'Сайт клиента', 'website' => 'https://example.test', 'clientName' => 'Анна', 'clientEmail' => 'anna@example.test', 'currency' => 'EUR'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('projects', ['uuid' => $id, 'title' => 'Сайт клиента', 'currency' => 'EUR']);
        $this->patchJson('/api/admin/projects/'.$id, ['title' => 'Новое название'])->assertOk()->assertJsonPath('data.name', 'Новое название');
        $this->getJson('/api/admin/projects')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guest_cannot_create_projects_with_401(): void
    {
        $this->postJson('/api/admin/projects', ['title' => 'Запрещено'])->assertUnauthorized();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_integer_project_id_is_not_a_public_route(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/projects/'.$project->id)->assertNotFound();
    }

    public function test_project_validation_rejects_unsafe_url_and_currency(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/admin/projects', ['title' => 'Сайт', 'website' => 'javascript:alert(1)', 'currency' => 'BAD'])->assertUnprocessable()->assertJsonValidationErrors(['website', 'currency']);
        $this->assertDatabaseCount('projects', 0);
    }
}
