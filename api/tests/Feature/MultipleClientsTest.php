<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccessToken;
use App\Models\ProjectClient;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultipleClientsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function link(ProjectClient $client): array
    {
        $plain = bin2hex(random_bytes(32));
        $token = $client->project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $plain)]);

        return [$plain, $token];
    }

    private function sessionFor(ProjectAccessToken $token): void
    {
        $this->withSession(['client_project_id' => $token->project_id, 'project_client_id' => $token->project_client_id, 'client_access_token_id' => $token->id]);
    }

    public function test_individual_rotation_revocation_and_disabling_do_not_affect_other_clients(): void
    {
        $project = Project::factory()->create();
        $john = $project->clients()->first();
        $anna = ProjectClient::factory()->for($project)->create(['name' => 'Anna Smith']);
        [$johnPlain, $johnToken] = $this->link($john);
        [$annaPlain, $annaToken] = $this->link($anna);
        $this->get('/access/'.$johnPlain)->assertSessionHas('project_client_id', $john->id);
        $this->get('/access/'.$annaPlain)->assertSessionHas('project_client_id', $anna->id);
        $admin = User::factory()->create();
        $root = '/api/admin/projects/'.$project->uuid.'/clients/';
        $this->actingAs($admin)->deleteJson($root.$john->uuid.'/access-links/'.$johnToken->id)->assertOk();
        $this->sessionFor($johnToken);
        $this->getJson('/api/client/tasks')->assertUnauthorized();
        $this->sessionFor($annaToken);
        $this->getJson('/api/client/me')->assertOk()->assertJsonPath('name', 'Anna Smith');
        $result = $this->postJson($root.$john->uuid.'/access-links')->assertCreated();
        $newJohn = ProjectAccessToken::where('token_hash', hash('sha256', basename($result->json('url'))))->firstOrFail();
        $this->assertNull($annaToken->fresh()->revoked_at);
        $this->get('/access/'.basename($result->json('url')))->assertRedirect();
        $this->getJson('/api/client/me')->assertOk()->assertJsonPath('id', $john->uuid);
        $this->actingAs($admin)->patchJson($root.$anna->uuid, ['active' => false])->assertOk();
        $this->sessionFor($annaToken);
        $this->getJson('/api/client/tasks')->assertUnauthorized();
        $this->sessionFor($newJohn);
        $this->getJson('/api/client/tasks')->assertOk();
        $this->patchJson($root.$anna->uuid, ['active' => true])->assertOk();
        $this->get('/access/'.$annaPlain)->assertRedirect(config('projectbrief.frontend_url').'/access-error?reason=invalid');
        $this->actingAs($admin)->postJson($root.$john->uuid.'/access-links')->assertCreated();
        $this->assertNotNull($newJohn->fresh()->revoked_at);
    }

    public function test_comment_approval_creation_and_attachment_identity_are_server_derived_and_private(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $john = $project->clients()->first();
        $john->update(['name' => 'John Smith', 'email' => 'john@example.com']);
        $anna = ProjectClient::factory()->for($project)->create(['name' => 'Anna Smith', 'email' => 'anna@example.com']);
        $task = Task::factory()->for($project)->create();
        [, $johnToken] = $this->link($john);
        [, $annaToken] = $this->link($anna);
        $this->sessionFor($johnToken);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'From John'])->assertOk()->assertJsonPath('data.comments.0.authorName', 'John Smith');
        $this->assertDatabaseHas('comments', ['text' => 'From John', 'project_client_id' => $john->id]);
        $this->sessionFor($annaToken);
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'From Anna'])->assertOk()->assertJsonPath('data.comments.1.authorName', 'Anna Smith');
        $this->postJson('/api/client/tasks/'.$task->uuid.'/comments', ['text' => 'Spoof', 'project_client_id' => $john->id])->assertUnprocessable();
        $this->postJson('/api/client/tasks/'.$task->uuid.'/approve')->assertOk();
        $this->assertDatabaseHas('task_history', ['task_id' => $task->id, 'event_type' => 'approved', 'project_client_id' => $anna->id]);
        $created = $this->postJson('/api/client/tasks', ['title' => 'Anna idea', 'location' => 'Mobile menu', 'description' => 'Describe', 'priority' => 'normal'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('task_history', ['task_id' => Task::where('uuid', $created)->first()->id, 'event_type' => 'created', 'project_client_id' => $anna->id]);
        $this->post('/api/client/tasks/'.$task->uuid.'/attachments', ['attachments' => [UploadedFile::fake()->create('example.txt', 1, 'text/plain')], 'project_client_id' => $john->id], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseHas('attachments', ['task_id' => $task->id, 'project_client_id' => $anna->id]);
        $task->comments()->create(['author_type' => 'client', 'text' => 'Legacy']);
        $response = $this->getJson('/api/client/tasks/'.$task->uuid)->assertOk()->assertJsonPath('data.comments.2.authorName', 'Клиент');
        $this->assertStringNotContainsString('@example.com', $response->getContent());
        $this->getJson('/api/client/me')->assertOk()->assertJsonPath('email', 'anna@example.com');
        $this->getJson('/api/client/project')->assertOk()->assertJsonMissingPath('data.clients')->assertJsonMissingPath('data.clientEmail')->assertJsonMissingPath('data.accessLinks');
    }

    public function test_management_is_admin_only_project_scoped_and_session_identity_cannot_be_swapped(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $client = $project->clients()->first();
        [, $token] = $this->link($client);
        $task = Task::factory()->for($other)->create();
        $this->sessionFor($token);
        $this->getJson('/api/client/project/'.$other->uuid.'/sections')->assertNotFound();
        $this->getJson('/api/admin/projects/'.$project->uuid.'/clients')->assertUnauthorized();
        $this->postJson('/api/admin/tasks/'.$task->uuid.'/reorder', ['direction' => 'up'])->assertUnauthorized();
        $this->postJson('/api/admin/projects/'.$project->uuid.'/sections', ['name' => 'Spoof'])->assertUnauthorized();
        $this->withSession(['project_client_id' => $other->clients()->first()->id]);
        $this->getJson('/api/client/me')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $root = '/api/admin/projects/'.$project->uuid.'/clients';
        $otherClient = $other->clients()->first();
        $this->patchJson($root.'/'.$otherClient->uuid, ['name' => 'Spoof'])->assertNotFound();
        $this->postJson($root.'/'.$otherClient->uuid.'/access-links')->assertNotFound();
        $this->patchJson($root.'/'.$client->uuid, ['project_id' => $other->id])->assertUnprocessable();
        $this->postJson($root, ['name' => 'New named client', 'email' => 'new@example.com'])->assertCreated();
        $this->assertDatabaseCount('project_access_tokens', 1);
        $this->getJson($root)->assertOk()->assertJsonMissingPath('data.0.accessLinks.0.token_hash')->assertJsonMissingPath('data.0.accessLinks.0.url');
    }
}
