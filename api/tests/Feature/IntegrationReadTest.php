<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\IntegrationTokens;
use App\Services\WebhookEvents;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationReadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['developer_notes' => 'PRIVATE_INTERNAL_NOTE']);
        $owner = $this->ownerFor($project);
        $comments = [];
        foreach ([null, $task->id] as $taskId) {
            foreach (['client', 'admin'] as $type) {
                $comments[] = Comment::create(['project_id' => $project->id, 'task_id' => $taskId, 'author_type' => $type, 'project_client_id' => $type === 'client' ? $project->clients->first()->id : null, 'author_user_id' => $type === 'admin' ? $owner->id : null, 'text' => 'Plain <script>alert(1)</script> text with personal@example.test']);
            }
        }

        return [$project, $task, $comments];
    }

    private function headers(Project $project, array $scopes = ['comments:read', 'attachments:read', 'tasks:read'], array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Trusted CRM', 'scopes' => $scopes, ...$extra])['secret']];
    }

    private function file(Task $task, ?Comment $comment = null): Attachment
    {
        $path = 'attachments/test/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, 'PRIVATE_BINARY_CONTENT');

        return Attachment::create(['task_id' => $comment ? $comment->task_id : $task->id, 'comment_id' => $comment?->id, 'original_name' => 'report.pdf', 'stored_name' => basename($path), 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => 22, 'uploaded_by_type' => 'client']);
    }

    public function test_comment_allowlist_general_and_tasks_and_safe_attachment_metadata_without_file_access(): void
    {
        [$project, $task, $comments] = $this->fixture();
        $file = $this->file($task, $comments[0]);
        $headers = $this->headers($project, ['comments:read']);
        $response = $this->getJson('/api/v1/projects/'.$project->uuid.'/comments', $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(4, 'data');
        $first = $response->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'projectId', 'taskId', 'author', 'text', 'createdAt', 'attachments'], array_keys($first));
        $this->assertSame(['type' => 'client', 'id' => $project->clients->first()->uuid], $first['author']);
        $this->assertSame($comments[0]->text, $first['text']);
        $this->assertSame(['id' => $file->uuid, 'name' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => 22], $first['attachments'][0]);
        $this->assertNull($response->json('data.1.author.id'));
        foreach (['PRIVATE_INTERNAL_NOTE', 'PRIVATE_BINARY_CONTENT', $file->path, 'downloadUrl', 'stored_name', 'authorName', 'unread', '<script>'] as $private) {
            $response->assertDontSee($private, false);
        }
        $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertForbidden();
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments?kind=general&authorType=client', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $comments[0]->uuid);
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments?kind=tasks&taskId='.$task->uuid.'&authorType=admin', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $comments[3]->uuid);
    }

    public function test_direct_lookup_by_webhook_id_and_reads_do_not_emit_or_mutate_comments(): void
    {
        [$project, $task, $comments] = $this->fixture();
        DB::table('webhook_endpoints')->insert(['uuid' => (string) Str::uuid(), 'workspace_id' => $project->workspace_id, 'name' => 'CRM', 'url' => 'https://example.com', 'events' => json_encode(['task.client_comment_created', 'project.client_comment_created']), 'enabled' => true, 'secret' => Crypt::encryptString('synthetic'), 'secret_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $headers = $this->headers($project);
        foreach ([$comments[0], $comments[2]] as $comment) {
            app(WebhookEvents::class)->clientComment($comment, $project, $comment->task_id ? $task : null);
            $event = json_decode(DB::table('webhook_events')->latest('id')->value('body'), true);
            $this->assertArrayNotHasKey('text', $event);
            $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$event['commentId'], $headers)->assertOk()->assertJsonPath('data.text', $comment->text)->assertJsonPath('data.taskId', $comment->task_id ? $task->uuid : null);
        }
        $this->assertDatabaseCount('webhook_events', 2);
        $this->postJson('/api/v1/projects/'.$project->uuid.'/comments', ['text' => 'External write prohibited'], $headers)->assertNotFound();
        $this->assertDatabaseCount('comments', 4);
    }

    public function test_workspace_scope_and_browser_identity_never_substitute_for_bearer_permissions(): void
    {
        [$project, , $comments] = $this->fixture();
        $url = '/api/v1/projects/'.$project->uuid.'/comments/'.$comments[0]->uuid;
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->ownerFor($project))->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->memberOf($project->workspace)->create())->getJson($url)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withSession(['client_project_id' => $project->id, 'project_client_id' => $project->clients->first()->id])->getJson($url)->assertUnauthorized();
        $this->getJson($url, $this->headers($project, ['projects:read', 'tasks:read']))->assertForbidden();
        $other = Project::factory()->create();
        $foreign = $this->headers($other);
        $this->getJson($url, $foreign)->assertNotFound();
        $this->getJson('/api/v1/projects/'.$other->uuid.'/comments/'.$comments[0]->uuid, $foreign)->assertNotFound();
        $this->withSession(['workspace_id' => $other->workspace_id])->getJson($url, $this->headers($project))->assertOk();
        $this->getJson('/api/v1/projects/'.$project->id.'/comments', $this->headers($project))->assertNotFound();
    }

    public function test_invalid_filters_and_cursors_fail_without_disclosing_records(): void
    {
        [$project, $task] = $this->fixture();
        $headers = $this->headers($project);
        foreach (['kind=private', 'authorType=owner', 'kind=general&taskId='.$task->uuid, 'taskId='.$task->uuid, 'kind=tasks&taskId=4', 'perPage=0', 'perPage=101', 'perPage[]=5', 'cursor=forged', 'page=1', 'unread=true'] as $query) {
            $this->getJson('/api/v1/projects/'.$project->uuid.'/comments?'.$query, $headers)->assertUnprocessable();
        }
        $other = Task::factory()->create();
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments?kind=tasks&taskId='.$other->uuid, $headers)->assertNotFound();
    }

    public function test_encrypted_cursor_is_stable_with_inserts_deletes_and_bound_to_token_filters_and_project(): void
    {
        [$project, $task, $comments] = $this->fixture();
        $headers = $this->headers($project);
        $base = '/api/v1/projects/'.$project->uuid.'/comments?perPage=1';
        $first = $this->getJson($base, $headers)->assertOk()->json();
        $this->assertSame($comments[0]->uuid, $first['data'][0]['id']);
        $cursor = rawurlencode($first['meta']['nextCursor']);
        Comment::create(['project_id' => $project->id, 'author_type' => 'client', 'text' => 'Inserted later']);
        $comments[1]->delete();
        $second = $this->getJson($base.'&cursor='.$cursor, $headers)->assertOk()->json();
        $this->assertSame($comments[2]->uuid, $second['data'][0]['id']);
        $last = $this->getJson($base.'&cursor='.rawurlencode($second['meta']['nextCursor']), $headers)->assertOk()->json();
        $this->assertSame($comments[3]->uuid, $last['data'][0]['id']);
        $this->assertFalse($last['meta']['hasMore']);
        $this->assertNull($last['meta']['nextCursor']);
        $this->getJson($base.'&cursor='.$cursor.'&kind=tasks', $headers)->assertUnprocessable();
        $this->getJson($base.'&cursor='.$cursor, $this->headers($project))->assertUnprocessable();
        $other = Project::factory()->for($project->workspace)->create();
        $this->getJson('/api/v1/projects/'.$other->uuid.'/comments?perPage=1&cursor='.$cursor, $headers)->assertUnprocessable();
    }

    public function test_soft_deleted_tasks_and_inconsistent_or_internal_comments_and_files_are_hidden(): void
    {
        [$project, $task, $comments] = $this->fixture();
        $headers = $this->headers($project);
        $taskFile = $this->file($task);
        $commentFile = $this->file($task, $comments[2]);
        $task->delete();
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments', $headers)->assertOk()->assertJsonCount(2, 'data');
        foreach ([$comments[2], $comments[3]] as $comment) {
            $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$comment->uuid, $headers)->assertNotFound();
            $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$comment->uuid.'/attachments', $headers)->assertNotFound();
        }
        foreach ([$taskFile, $commentFile] as $file) {
            $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertNotFound();
        }
        $internal = Comment::create(['project_id' => $project->id, 'text' => 'PRIVATE_INTERNAL', 'author_type' => 'internal']);
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$internal->uuid, $headers)->assertNotFound();
        $foreignTask = Task::factory()->create();
        $invalid = Comment::create(['project_id' => $project->id, 'task_id' => $foreignTask->id, 'text' => 'PRIVATE_FOREIGN', 'author_type' => 'client']);
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$invalid->uuid, $headers)->assertNotFound();
    }

    public function test_attachment_lists_separate_task_and_comment_files_and_stream_with_parent_scopes(): void
    {
        [$project, $task, $comments] = $this->fixture();
        $taskFile = $this->file($task);
        $generalFile = $this->file($task, $comments[0]);
        $commentFile = $this->file($task, $comments[2]);
        $headers = $this->headers($project);
        $this->getJson('/api/v1/projects/'.$project->uuid.'/tasks/'.$task->uuid.'/attachments', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $taskFile->uuid);
        foreach ([[$comments[0], $generalFile], [$comments[2], $commentFile]] as [$comment, $file]) {
            $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$comment->uuid.'/attachments', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $file->uuid);
        }
        foreach ([$taskFile, $generalFile, $commentFile] as $file) {
            $response = $this->get('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame('PRIVATE_BINARY_CONTENT', $response->streamedContent());
            $this->assertCount(0, $response->headers->getCookies());
        }
        $onlyAttachments = $this->headers($project, ['attachments:read']);
        $this->getJson('/api/v1/attachments/'.$taskFile->uuid.'/download', $onlyAttachments)->assertForbidden();
        $this->getJson('/api/v1/attachments/'.$commentFile->uuid.'/download', $onlyAttachments)->assertForbidden();
        $taskScopes = $this->headers($project, ['attachments:read', 'tasks:read']);
        $commentScopes = $this->headers($project, ['attachments:read', 'comments:read']);
        $this->get('/api/v1/attachments/'.$taskFile->uuid.'/download', $taskScopes)->assertOk();
        $this->getJson('/api/v1/attachments/'.$taskFile->uuid.'/download', $commentScopes)->assertForbidden();
        $this->getJson('/api/v1/attachments/'.$commentFile->uuid.'/download', $taskScopes)->assertForbidden();
        $this->get('/api/v1/attachments/'.$commentFile->uuid.'/download', $commentScopes)->assertOk();
    }

    public function test_attachment_workspace_parent_mismatch_deleted_comment_missing_file_and_unsafe_path_are_denied(): void
    {
        [$project, $task, $comments] = $this->fixture();
        $file = $this->file($task, $comments[2]);
        $headers = $this->headers($project);
        $other = Project::factory()->create();
        $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $this->headers($other))->assertNotFound();
        $this->getJson('/api/v1/projects/'.$other->uuid.'/comments/'.$comments[2]->uuid.'/attachments', $this->headers($other))->assertNotFound();
        $file->update(['task_id' => Task::factory()->for($project)->create()->id]);
        $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertNotFound();
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$comments[2]->uuid.'/attachments', $headers)->assertOk()->assertJsonCount(0, 'data');
        $file->update(['task_id' => $task->id, 'path' => 'attachments/../private-secret']);
        Storage::disk('local')->put('private-secret', 'PRIVATE');
        $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertNotFound();
        $file->update(['path' => 'attachments/missing.pdf']);
        $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertNotFound();
        $comments[2]->delete();
        $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertNotFound();
    }

    public function test_legacy_task_comments_without_project_column_remain_visible_only_through_their_parent_task(): void
    {
        [$project, $task, $comments] = $this->fixture();
        $comments[2]->update(['project_id' => null]);
        $file = $this->file($task, $comments[2]);
        $headers = $this->headers($project);
        $this->getJson('/api/v1/projects/'.$project->uuid.'/comments/'.$comments[2]->uuid, $headers)->assertOk()->assertJsonPath('data.projectId', $project->uuid);
        $this->get('/api/v1/attachments/'.$file->uuid.'/download', $headers)->assertOk();
        $other = Project::factory()->for($project->workspace)->create();
        $this->getJson('/api/v1/projects/'.$other->uuid.'/comments/'.$comments[2]->uuid, $headers)->assertNotFound();
        $this->getJson('/api/v1/attachments/'.$file->uuid.'/download', $this->headers(Project::factory()->create()))->assertNotFound();
    }

    public function test_expired_revoked_and_missing_bearers_cannot_read_or_download_and_all_scopes_can_be_created(): void
    {
        [$project, $task, $comments] = $this->fixture();
        $file = $this->file($task);
        $token = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Revoked', 'scopes' => IntegrationTokens::SCOPES]);
        DB::table('integration_tokens')->where('uuid', $token['id'])->update(['revoked_at' => now()]);
        foreach ([[], ['Authorization' => 'Bearer '.$token['secret']], $this->headers($project, IntegrationTokens::SCOPES, ['expiresAt' => now()->subSecond()])] as $headers) {
            foreach (['/api/v1/projects/'.$project->uuid.'/comments', '/api/v1/attachments/'.$file->uuid.'/download'] as $url) {
                $this->getJson($url, $headers)->assertUnauthorized();
            }
        }
        $this->actingAs($this->ownerFor($project))->postJson('/api/admin/integrations/tokens', ['name' => 'All seven', 'scopes' => IntegrationTokens::SCOPES])->assertCreated();
        $this->getJson('/api/admin/integrations')->assertOk()->assertJsonCount(7, 'scopes');
    }
}
