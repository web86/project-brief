<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Services\DocxProjectExporter;
use App\Services\IntegrationTokens;
use App\Services\ProjectExportData;
use App\Services\TaskDescription;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskDescriptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_server_sanitizes_html_and_preserves_only_supported_formatting(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $task = Task::factory()->for($project)->create();
        $html = '<p onclick="alert(1)" style="color:red">Текст <b>bold</b> <i>italic</i></p><ul><li>One</li><li>Two</li></ul><a href="javascript:alert(1)">bad</a><a href="https://example.com">safe</a><script>alert(2)</script><iframe src="https://bad.test">hidden</iframe><svg onload="evil()" />';
        $data = $this->patchJson('/api/admin/tasks/'.$task->uuid, ['description' => 'ignored', 'descriptionHtml' => $html])->assertOk()->json('data');
        foreach (['onclick', 'style=', 'javascript:', '<script', 'alert(', '<iframe', '<svg'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $data['descriptionHtml']);
        }
        $this->assertStringContainsString('<strong>bold</strong>', $data['descriptionHtml']);
        $this->assertStringContainsString('<em>italic</em>', $data['descriptionHtml']);
        $this->assertStringContainsString('<ul><li>One</li><li>Two</li></ul>', $data['descriptionHtml']);
        $this->assertStringContainsString('noopener noreferrer', $data['descriptionHtml']);
        $this->assertStringContainsString('Текст', $data['description']);
        $this->assertStringNotContainsString('<', $data['description']);
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['description' => 'Legacy <literal> & text'])->assertOk()->assertJsonPath('data.description', 'Legacy <literal> & text')->assertJsonPath('data.descriptionHtml', null);
    }

    public function test_formatted_description_exports_rich_spans_and_safe_links_to_docx(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $section = $project->briefSections()->create(['name' => 'Website', 'position' => 1]);
        $task = Task::factory()->for($project)->create(['project_section_id' => $section->id]);
        $html = '<p><strong>Bold requirement</strong> <em>Italic note</em></p><ol><li>First</li><li>Second</li></ol><p><a href="https://example.com/spec">Specification</a></p>';
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['description' => 'fallback', 'descriptionHtml' => $html])->assertOk();
        $document = app(ProjectExportData::class)->build($project, 'all', 'en');
        $description = collect($document['sections'][0]['tasks'][0]['content'])->firstWhere('key', 'description');
        $this->assertTrue($description['richText'][0]['content'][0]['bold']);
        $this->assertSame('number', $description['richText'][1]['type']);
        $file = tempnam(sys_get_temp_dir(), 'brief-rich-docx-');
        try {
            file_put_contents($file, app(DocxProjectExporter::class)->render($document));
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($file));
            $xml = $zip->getFromName('word/document.xml');
            $relationships = $zip->getFromName('word/_rels/document.xml.rels');
            $zip->close();
            $this->assertStringContainsString('Bold requirement', $xml);
            $this->assertStringContainsString('<w:b', $xml);
            $this->assertStringContainsString('<w:i', $xml);
            $this->assertStringContainsString('https://example.com/spec', $relationships);
            $this->assertStringNotContainsString('&lt;strong', $xml);
        } finally {
            unlink($file);
        }
    }

    public function test_integration_task_creation_uses_the_same_server_normalizer(): void
    {
        $project = Project::factory()->create();
        $token = app(IntegrationTokens::class)->create($project->workspace_id, ['name' => 'Formatting test', 'scopes' => ['tasks:write']]);
        $response = $this->postJson('/api/v1/projects/'.$project->uuid.'/tasks', ['title' => 'Formatted API task', 'location' => 'Website', 'priority' => 'normal', 'description' => 'Untrusted fallback',
            'descriptionHtml' => '<p><strong>Safe</strong></p><script>bad()</script>'], ['Authorization' => 'Bearer '.$token['secret'], 'Idempotency-Key' => 'formatted-create']);
        $response->assertCreated()->assertJsonPath('data.description', 'Safe')->assertJsonPath('data.descriptionHtml', '<p><strong>Safe</strong></p>');
    }

    public function test_plain_text_updates_by_an_older_runtime_do_not_resurrect_stale_html(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $task = Task::factory()->for($project)->create();
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['description' => 'x', 'descriptionHtml' => '<p><strong>Original</strong></p>'])->assertOk();
        DB::table('tasks')->where('id', $task->id)->update(['description' => 'Edited by old runtime']);
        $this->getJson('/api/admin/tasks/'.$task->uuid)->assertOk()->assertJsonPath('data.descriptionHtml', null)->assertJsonPath('data.description', 'Edited by old runtime');
    }

    public function test_blank_unsafe_only_and_overly_complex_descriptions_are_rejected(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->ownerFor($project));
        $task = Task::factory()->for($project)->create();
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['description' => 'x', 'descriptionHtml' => '<script>bad</script>'])->assertUnprocessable();
        $this->patchJson('/api/admin/tasks/'.$task->uuid, ['description' => 'x', 'descriptionHtml' => str_repeat('<div>', 50).'x'.str_repeat('</div>', 50)])->assertUnprocessable();
        $this->assertSame('<p>安全 Türkçe</p>', TaskDescription::sanitize('<p>安全 Türkçe</p>')['html']);
    }
}
