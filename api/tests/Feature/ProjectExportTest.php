<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectExportData;
use App\Services\TaskAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ProjectExportTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $project = Project::factory()->create(['title' => 'Web86 — Проект', 'website_url' => 'https://example.test', 'sections' => ['Главная', 'Каталог', 'Пусто']]);
        $this->actingAs(User::factory()->owner($project->workspace)->create());
        $sections = $project->briefSections;
        Task::factory()->for($project)->create(['project_section_id' => $sections[1]->id, 'position' => 1, 'title' => 'Каталог товаров', 'client_approved' => true, 'description' => 'Описание каталога', 'expected_result' => 'Результат', 'priority' => 'high', 'status' => 'in_progress', 'estimate_hours' => 23.5, 'price' => 987.25, 'developer_notes' => 'SECRET_DEVELOPER_NOTES']);
        Task::factory()->for($project)->create(['project_section_id' => $sections[0]->id, 'position' => 2, 'title' => 'Вторая задача', 'client_approved' => true]);
        Task::factory()->for($project)->create(['project_section_id' => $sections[0]->id, 'position' => 1, 'title' => 'Первая задача', 'client_approved' => false]);

        return $project;
    }

    private function payload(string $format = 'docx', string $locale = 'ru'): array
    {
        return ['format' => $format, 'scope' => 'all', 'locale' => $locale];
    }

    private function xml(string $bytes): string
    {
        $file = tempnam(sys_get_temp_dir(), 'test-docx-');
        try {
            file_put_contents($file, $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($file));
            $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
            $this->assertNotFalse($zip->getFromName('_rels/.rels'));
            $xml = $zip->getFromName('word/document.xml');
            $this->assertNotFalse($xml);
            $footer = $zip->getFromName('word/footer1.xml');
            $this->assertNotFalse($footer);
            $zip->close();
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML($xml));

            return $xml.$footer;
        } finally {
            unlink($file);
        }
    }

    public function test_export_uses_existing_project_access_for_both_formats_and_denies_guessed_representations(): void
    {
        $project = $this->project();
        $owner = auth()->user();
        $admin = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $admin->workspaceMembers()->first()->id]);
        foreach (['pdf', 'docx'] as $format) {
            $url = '/api/admin/projects/'.$project->uuid.'/export';
            $this->actingAs($owner)->postJson($url, $this->payload($format))->assertOk();
            $this->actingAs($admin)->postJson($url, $this->payload($format))->assertOk();
            foreach ([User::factory()->memberOf($project->workspace)->create(), User::factory()->owner()->create(), User::factory()->memberOf(Project::factory()->create()->workspace)->create()] as $denied) {
                $this->actingAs($denied)->withSession(['workspace_id' => $denied->workspaceMembers()->first()->workspace_id])->postJson($url, $this->payload($format))->assertNotFound();
            }
            $this->actingAs($admin)->withSession(['workspace_id' => $project->workspace_id]);
            $this->getJson($url)->assertNotFound();
            $this->getJson($url.'.'.$format)->assertNotFound();
        }
        $client = $project->clients()->first();
        $plain = str_repeat('a', 64);
        $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', $plain)]);
        $this->get('/access/'.$plain)->assertRedirect();
        foreach (['pdf', 'docx'] as $format) {
            $this->postJson('/api/admin/projects/'.$project->uuid.'/export', $this->payload($format))->assertUnauthorized();
        }
        $this->postJson('/api/client/project/'.$project->uuid.'/export', $this->payload())->assertNotFound();
    }

    public function test_shared_allowlist_preserves_order_numbers_scope_and_selected_fields_without_private_data(): void
    {
        $project = $this->project();
        $foreign = Task::factory()->create(['title' => 'SECRET_FOREIGN_TASK']);
        $builder = app(ProjectExportData::class);
        $all = $builder->build($project, 'all', 'ru');
        $this->assertSame(['Главная', 'Каталог'], array_column($all['sections'], 'name'));
        $this->assertSame(['1.1', '1.2'], array_column($all['sections'][0]['tasks'], 'number'));
        $this->assertSame('2.1', $all['sections'][1]['tasks'][0]['number']);
        $this->assertSame('Первая задача', $all['sections'][0]['tasks'][0]['title']);
        $text = json_encode($all, JSON_UNESCAPED_UNICODE);
        foreach (['SECRET_DEVELOPER_NOTES', 'SECRET_FOREIGN_TASK', '23.5', '987.25', 'developer_notes', 'project_id', 'project_section_id', 'uuid', 'access_token', 'token_hash', 'storage', 'workspace_id', 'in_progress', 'high'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $approved = $builder->build($project, 'approved', 'en', ['estimateHours' => true, 'price' => true]);
        $this->assertSame(['1.2'], array_column($approved['sections'][0]['tasks'], 'number'));
        $this->assertSame('2.1', $approved['sections'][1]['tasks'][0]['number']);
        $text = json_encode($approved, JSON_UNESCAPED_UNICODE);
        foreach (['23.5 hours', '987.25 RUB', 'Description', 'Expected result', 'Priority', 'High', 'In progress', 'Описание каталога'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $bare = $builder->build($project, 'all', 'en', array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), false));
        foreach ($bare['sections'] as $section) {
            foreach ($section['tasks'] as $task) {
                $this->assertSame([], $task['content']);
            }
        }
        $project->tasks()->where('project_section_id', $project->briefSections->first()->id)->update(['client_approved' => false]);
        $filtered = $builder->build($project, 'approved', 'en');
        $this->assertSame(['Каталог'], array_column($filtered['sections'], 'name'));
        $this->assertSame(2, $filtered['sections'][0]['number']);
        // Even an inconsistent cross-project section reference cannot introduce foreign data.
        $foreign->update(['project_section_id' => $project->briefSections->first()->id]);
        $this->assertStringNotContainsString('SECRET_FOREIGN_TASK', json_encode($builder->build($project, 'all', 'en')));
    }

    public function test_docx_download_is_valid_localized_cyrillic_and_escapes_markup(): void
    {
        $project = $this->project();
        $project->tasks()->first()->update(['description' => 'Текст <img src="https://private.test/SECRET_PUSH"> & результат']);
        foreach (['ru' => 'ТЕХНИЧЕСКОЕ ЗАДАНИЕ', 'en' => 'TECHNICAL SPECIFICATION'] as $locale => $title) {
            $response = $this->postJson('/api/admin/projects/'.$project->uuid.'/export', $this->payload('docx', $locale))->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            $this->assertMatchesRegularExpression('/attachment; filename=[a-z0-9-]+-specification-\d{4}-\d{2}-\d{2}\.docx/', $response->headers->get('Content-Disposition'));
            $xml = $this->xml($response->streamedContent());
            foreach ([$title, 'Первая задача', 'Каталог товаров', 'ProjectBrief', '&lt;img', '&amp;'] as $expected) {
                $this->assertStringContainsString($expected, $xml);
            }
            foreach (['SECRET_DEVELOPER_NOTES', '987.25', '23.5', '<img src=', 'token_hash', 'workspace_id'] as $secret) {
                $this->assertStringNotContainsString($secret, $xml);
            }
            $options = [...$this->payload('docx', $locale), 'scope' => 'approved', 'fields' => ['price' => true, 'estimateHours' => true]];
            $xml = $this->xml($this->postJson('/api/admin/projects/'.$project->uuid.'/export', $options)->assertOk()->streamedContent());
            $this->assertStringNotContainsString('Первая задача', $xml);
            $this->assertStringContainsString('987.25 RUB', $xml);
            $this->assertStringContainsString('23.5', $xml);
        }
    }

    public function test_pdf_renders_cyrillic_from_safe_escaped_source_and_embeds_packaged_font(): void
    {
        $project = $this->project();
        $client = $project->clients()->first();
        $project->accessTokens()->create(['project_client_id' => $client->id, 'token_hash' => hash('sha256', 'SECRET_TOKEN')]);
        $task = $project->tasks()->first();
        PushSubscription::factory()->create(['user_id' => auth()->id(), 'endpoint' => 'https://fcm.googleapis.com/SECRET_PUSH_ENDPOINT']);
        $task->history()->create(['actor_type' => 'admin', 'event_type' => 'developer_notes', 'meta' => ['text' => 'SECRET_HISTORY']]);
        $task->comments()->create(['author_type' => 'admin', 'text' => 'SECRET_COMMENT']);
        $task->attachments()->create(['uuid' => (string) Str::uuid(), 'uploaded_by_type' => 'admin', 'stored_name' => 'secret.txt', 'original_name' => 'secret.txt', 'disk' => 'local', 'path' => 'SECRET_STORAGE_PATH', 'mime_type' => 'text/plain', 'size' => 1]);
        foreach (['ru', 'en'] as $locale) {
            $document = app(ProjectExportData::class)->build($project, 'all', $locale);
            $source = view('exports.specification', ['document' => $document])->render();
            $this->assertStringContainsString($locale === 'ru' ? 'Описание' : 'Description', $source);
            $this->assertStringContainsString('Каталог товаров', $source);
            foreach (['SECRET_DEVELOPER_NOTES', 'SECRET_STORAGE_PATH', 'SECRET_TOKEN', hash('sha256', 'SECRET_TOKEN'), 'SECRET_COMMENT', 'SECRET_HISTORY', 'SECRET_PUSH_ENDPOINT', 'developer_notes', $task->uuid, $project->uuid, $client->uuid] as $secret) {
                $this->assertStringNotContainsString($secret, $source);
            }
            $response = $this->postJson('/api/admin/projects/'.$project->uuid.'/export', $this->payload('pdf', $locale))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $bytes = $response->streamedContent();
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertGreaterThan(10000, strlen($bytes));
            $this->assertStringContainsString('DejaVuSans', $bytes);
            $this->assertStringContainsString('/ToUnicode', $bytes);
            $this->assertStringNotContainsString('SECRET_DEVELOPER_NOTES', $bytes);
            $temporaryBefore = glob(sys_get_temp_dir().'/projectbrief-docx-*');
            $xml = $this->xml($this->postJson('/api/admin/projects/'.$project->uuid.'/export', $this->payload('docx', $locale))->assertOk()->streamedContent());
            $this->assertSame($temporaryBefore, glob(sys_get_temp_dir().'/projectbrief-docx-*'));
            foreach (['SECRET_DEVELOPER_NOTES', 'SECRET_STORAGE_PATH', 'SECRET_TOKEN', hash('sha256', 'SECRET_TOKEN'), 'SECRET_COMMENT', 'SECRET_HISTORY', 'SECRET_PUSH_ENDPOINT', $task->uuid, $project->uuid] as $secret) {
                $this->assertStringNotContainsString($secret, $xml);
            }
        }
    }

    public function test_unknown_options_invalid_values_and_empty_scope_are_rejected(): void
    {
        $project = $this->project();
        $url = '/api/admin/projects/'.$project->uuid.'/export';
        foreach ([['format' => 'doc'], ['scope' => 'selected'], ['locale' => 'fr'], ['fields' => ['developerNotes' => true]], ['fields' => ['price' => 'true']], ['fields' => null], ['fields' => ['status' => null]], ['options' => 'secret'], ['project_id' => $project->id]] as $invalid) {
            $this->postJson($url, [...$this->payload(), ...$invalid])->assertUnprocessable();
        }
        $project->tasks()->update(['client_approved' => false]);
        foreach (['ru', 'en'] as $locale) {
            $this->postJson($url, [...$this->payload('pdf', $locale), 'scope' => 'approved'])->assertUnprocessable()->assertJsonPath('errors.scope.0', trans('exports.empty', [], $locale));
        }
        $project->update(['title' => '💡']);
        $this->postJson($url, $this->payload())->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=project-specification-'.now()->toDateString().'.docx');
        $project->tasks()->delete();
        $this->postJson($url, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_all_status_and_priority_values_have_human_readable_translations(): void
    {
        $project = $this->project();
        foreach (['ru', 'en'] as $locale) {
            foreach (array_keys(TaskAudit::STATUSES) as $status) {
                foreach (['low', 'normal', 'high'] as $priority) {
                    $project->tasks()->update(compact('status', 'priority'));
                    $data = app(ProjectExportData::class)->build($project, 'all', $locale);
                    $content = $data['sections'][0]['tasks'][0]['content'];
                    $this->assertContains(trans('exports.statuses.'.$status, [], $locale), array_column($content, 'value'));
                    $this->assertContains(trans('exports.priorities.'.$priority, [], $locale), array_column($content, 'value'));
                }
            }
        }
    }
}
