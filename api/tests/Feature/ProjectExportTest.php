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
            $styles = $zip->getFromName('word/styles.xml');
            $this->assertNotFalse($styles);
            $zip->close();
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML($xml));

            return $xml.$footer.$styles;
        } finally {
            unlink($file);
        }
    }

    public function test_export_uses_existing_project_access_for_both_formats_and_denies_guessed_representations(): void
    {
        $project = $this->project();
        $owner = auth()->user();
        $admin = User::factory()->memberOf($project->workspace)->create();
        $project->members()->create(['workspace_id' => $project->workspace_id, 'workspace_member_id' => $admin->workspaceMembers()->first()->id, 'permissions' => ['export_project' => true]]);
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
        $this->postJson('/api/client/project/'.$project->uuid.'/export', $this->payload())->assertOk();
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
        foreach (['23.5 h', "987.25\u{00A0}₽", 'Description', 'Expected result', 'Priority', 'High', 'In progress', 'Описание каталога'] as $expected) {
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
        foreach (['ru' => 'Техническое задание', 'en' => 'Technical specification'] as $locale => $title) {
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
            $this->assertStringContainsString(($locale === 'ru' ? '987,25' : '987.25')."\u{00A0}₽", $xml);
            $this->assertStringContainsString($locale === 'ru' ? '23,5' : '23.5', $xml);
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
        foreach ([['format' => 'doc'], ['scope' => 'selected'], ['locale' => 'fr'], ['fields' => ['privateUnknownField' => true]], ['fields' => ['price' => 'true']], ['fields' => null], ['fields' => ['status' => null]], ['options' => 'secret'], ['project_id' => $project->id]] as $invalid) {
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

    private function documentXPath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML(substr($xml, 0, strpos($xml, '</w:document>') + strlen('</w:document>'))));

        return new \DOMXPath($dom);
    }

    public function test_totals_use_exported_tasks_and_distinguish_zero_from_missing_values(): void
    {
        $project = $this->project();
        $ordered = $project->tasks()->orderBy('project_section_id')->orderBy('position')->get();
        $ordered[0]->update(['estimate_hours' => '10.10', 'price' => '1000.10']);
        $ordered[1]->update(['estimate_hours' => 0, 'price' => 0]);
        $builder = app(ProjectExportData::class);
        $fields = ['estimateHours' => true, 'price' => true];
        $all = $builder->build($project, 'all', 'en', $fields);
        $this->assertSame(3, $all['totals']['taskCount']);
        $this->assertSame(2, $all['totals']['approvedCount']);
        $this->assertSame('33.60', $all['totals']['estimateHours']['value']);
        $this->assertSame('1987.35', $all['totals']['price']['value']);
        foreach (['estimateHours', 'price'] as $key) {
            $this->assertSame(3, $all['totals'][$key]['providedTaskCount']);
            $this->assertNull($all['totals'][$key]['coverage']);
        }
        $approved = $builder->build($project, 'approved', 'en', $fields);
        $this->assertSame(2, $approved['totals']['taskCount']);
        $this->assertSame(2, $approved['totals']['approvedCount']);
        $this->assertSame('23.50', $approved['totals']['estimateHours']['value']);
        $this->assertSame('987.25', $approved['totals']['price']['value']);
        $this->assertNull($approved['totals']['price']['coverage']);
        $ordered[1]->update(['estimate_hours' => null, 'price' => null]);
        foreach (['ru', 'en'] as $locale) {
            $data = $builder->build($project, 'approved', $locale, $fields);
            $this->assertSame(1, $data['totals']['estimateHours']['providedTaskCount']);
            $this->assertSame(1, $data['totals']['price']['providedTaskCount']);
            $this->assertSame($locale === 'ru' ? 'Оценка указана для 1 из 2 задач' : 'Estimate provided for 1 of 2 tasks', $data['totals']['estimateHours']['coverage']);
            $this->assertSame($locale === 'ru' ? 'Стоимость указана для 1 из 2 задач' : 'Price provided for 1 of 2 tasks', $data['totals']['price']['coverage']);
        }
        $project->tasks()->update(['estimate_hours' => null, 'price' => null]);
        $missing = $builder->build($project, 'all', 'en', $fields);
        $this->assertSame('0.00', $missing['totals']['estimateHours']['value']);
        $this->assertSame('0.00', $missing['totals']['price']['value']);
        $this->assertSame('Price provided for 0 of 3 tasks', $missing['totals']['price']['coverage']);
        $default = $builder->build($project, 'all', 'en');
        $this->assertSame(['taskCount' => 3, 'approvedCount' => 2], $default['totals']);
        $this->assertCount(2, $default['summary']);
        $project->tasks()->where('id', '!=', $ordered[0]->id)->delete();
        $this->assertSame('Estimate provided for 0 of 1 task', $builder->build($project, 'all', 'en', $fields)['totals']['estimateHours']['coverage']);
        $this->assertSame('Оценка указана для 0 из 1 задачи', $builder->build($project, 'all', 'ru', $fields)['totals']['estimateHours']['coverage']);
    }

    public function test_currency_and_decimal_totals_are_localized_and_exact(): void
    {
        $project = $this->project();
        $tasks = $project->tasks()->get();
        $tasks[0]->update(['estimate_hours' => '0.10', 'price' => '0.10']);
        $tasks[1]->update(['estimate_hours' => '0.20', 'price' => '0.20']);
        $tasks[2]->update(['estimate_hours' => 0, 'price' => 0]);
        $builder = app(ProjectExportData::class);
        $small = $builder->build($project, 'all', 'en', ['estimateHours' => true, 'price' => true]);
        $this->assertSame('0.30', $small['totals']['price']['value']);
        $this->assertSame('0.30', $small['totals']['estimateHours']['value']);
        $project->tasks()->update(['price' => '333333333333.33']);
        $large = $builder->build($project, 'all', 'en', ['price' => true]);
        $this->assertSame('999999999999.99', $large['totals']['price']['value']);
        $project->tasks()->update(['price' => 42000]);
        foreach (['RUB' => '₽', 'USD' => '$', 'EUR' => '€', 'TRY' => '₺', 'GBP' => 'GBP'] as $currency => $symbol) {
            $project->update(['currency' => $currency]);
            foreach (['ru', 'en'] as $locale) {
                $data = $builder->build($project, 'all', $locale, ['price' => true]);
                $this->assertSame(($locale === 'ru' ? "126\u{00A0}000" : '126,000')."\u{00A0}".$symbol, $data['totals']['price']['formatted']);
                $metadata = $data['sections'][0]['tasks'][0]['metadata'];
                $this->assertStringContainsString(($locale === 'ru' ? "42\u{00A0}000" : '42,000')."\u{00A0}".$symbol, $metadata);
            }
        }
    }

    public function test_compact_metadata_and_summary_render_consistently_for_each_financial_option(): void
    {
        $project = $this->project();
        foreach (['ru', 'en'] as $locale) {
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$hours, $price]) {
                $fields = ['estimateHours' => $hours, 'price' => $price];
                $data = app(ProjectExportData::class)->build($project, 'all', $locale, $fields);
                $source = view('exports.specification', ['document' => $data])->render();
                $xml = $this->xml($this->postJson('/api/admin/projects/'.$project->uuid.'/export', [...$this->payload('docx', $locale), 'fields' => $fields])->assertOk()->streamedContent());
                $xpath = $this->documentXPath($xml);
                foreach ($data['sections'] as $section) {
                    foreach ($section['tasks'] as $task) {
                        $this->assertStringContainsString('<p class="task-metadata">'.$task['metadata'].'</p>', $source);
                        $metadataNodes = $xpath->query('//w:p[w:r/w:t[contains(., " · ")]]');
                        $found = false;
                        foreach ($metadataNodes as $node) {
                            if ($node->textContent === $task['metadata']) {
                                $this->assertSame('18', $xpath->evaluate('string(w:r/w:rPr/w:sz/@w:val)', $node));
                                $this->assertSame('586174', $xpath->evaluate('string(w:r/w:rPr/w:color/@w:val)', $node));
                                $found = true;
                                break;
                            }
                        }
                        $this->assertTrue($found, 'Task metadata occupies one smaller styled paragraph.');
                    }
                }
                $this->assertStringContainsString('font-size: 8.5pt', $source);
                $this->assertGreaterThan(strpos($source, 'Каталог товаров'), strpos($source, '<div class="summary">'));
                foreach ($data['summary'] as $row) {
                    $this->assertStringContainsString($row['label'], $source);
                    $this->assertStringContainsString($row['label'].': '.$row['value'], $xml);
                    if ($row['coverage']) {
                        $this->assertStringContainsString($row['coverage'], $source);
                        $this->assertStringContainsString($row['coverage'], $xml);
                    }
                }
                foreach (['estimateHours' => $hours, 'price' => $price] as $key => $enabled) {
                    $label = trans('exports.'.$key.'Total', [], $locale);
                    $this->assertSame($enabled, str_contains($source, $label));
                    $this->assertSame($enabled, str_contains($xml, $label));
                }
                $pdf = $this->postJson('/api/admin/projects/'.$project->uuid.'/export', [...$this->payload('pdf', $locale), 'fields' => $fields])->assertOk()->streamedContent();
                $this->assertStringStartsWith('%PDF-', $pdf);
            }
        }
        $fields = array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), false);
        $bare = app(ProjectExportData::class)->build($project, 'all', 'en', $fields);
        $this->assertSame('', $bare['sections'][0]['tasks'][0]['metadata']);
        $this->assertStringNotContainsString('<p class="task-metadata">', view('exports.specification', ['document' => $bare])->render());
        $financialOnly = app(ProjectExportData::class)->build($project, 'all', 'en', [...$fields, 'estimateHours' => true, 'price' => true]);
        $metadata = $financialOnly['sections'][1]['tasks'][0]['metadata'];
        $this->assertSame("Estimate: 23.5 h · Price: 987.25\u{00A0}₽", $metadata);
    }

    public function test_optional_filenames_dates_and_custom_title_are_safe_in_both_formats(): void
    {
        $project = $this->project();
        $task = $project->tasks()->where('title', 'Каталог товаров')->firstOrFail();
        $task->update(['client_approved_at' => '2026-10-18 12:00:00']);
        $unapproved = $project->tasks()->where('client_approved', false)->firstOrFail();
        $unapproved->update(['client_approved_at' => '2026-10-17 12:00:00']);
        foreach (['C:\\SECRET_PATH\\requirements & scope.pdf', '../../homepage-reference.jpg', "draft\u{202E}.pdf", '<draft & plan>.pdf', '.'] as $filename) {
            $task->attachments()->create(['uploaded_by_type' => 'admin', 'original_name' => $filename, 'stored_name' => 'SECRET_INTERNAL_UUID.pdf', 'disk' => 'local', 'path' => 'SECRET_STORAGE_PATH', 'mime_type' => 'application/pdf', 'size' => 1]);
        }
        $fields = ['attachments' => true, 'approvalDate' => true];
        foreach (['ru', 'en'] as $locale) {
            $data = app(ProjectExportData::class)->build($project, 'all', $locale, $fields, ['documentTitle' => '  План <работ> & estimate  ']);
            $this->assertSame('План <работ> & estimate', $data['title']);
            $this->assertSame(['requirements & scope.pdf', 'homepage-reference.jpg', 'draft.pdf', '<draft & plan>.pdf'], $data['sections'][1]['tasks'][0]['attachments']);
            $date = $locale === 'ru' ? '18.10.2026' : '18 Oct 2026';
            $this->assertStringContainsString($date, $data['sections'][1]['tasks'][0]['metadata']);
            $this->assertStringNotContainsString('17.10.2026', json_encode($data));
            $this->assertStringNotContainsString('17 Oct 2026', json_encode($data));
            foreach ($data['sections'][0]['tasks'] as $withoutDate) {
                $this->assertStringNotContainsString(trans('exports.approvedOn', [], $locale), $withoutDate['metadata']);
                $this->assertSame([], $withoutDate['attachments']);
            }
            $source = view('exports.specification', ['document' => $data])->render();
            $this->assertStringContainsString('План &lt;работ&gt; &amp; estimate', $source);
            $this->assertStringContainsString('&lt;draft &amp; plan&gt;.pdf', $source);
            $xml = $this->xml($this->postJson('/api/admin/projects/'.$project->uuid.'/export', [...$this->payload('docx', $locale), 'fields' => $fields, 'documentTitle' => '  План <работ> & estimate  '])->assertOk()->streamedContent());
            $this->assertStringContainsString('План &lt;работ&gt; &amp; estimate', $xml);
            $this->assertStringContainsString('requirements &amp; scope.pdf', $xml);
            $this->assertStringContainsString($date, $xml);
            foreach (['SECRET_PATH', 'SECRET_INTERNAL_UUID', 'SECRET_STORAGE_PATH', $task->uuid, $project->uuid] as $secret) {
                $this->assertStringNotContainsString($secret, $source);
                $this->assertStringNotContainsString($secret, $xml);
            }
            $this->assertStringStartsWith('%PDF-', $this->postJson('/api/admin/projects/'.$project->uuid.'/export', [...$this->payload('pdf', $locale), 'fields' => $fields, 'documentTitle' => 'План <работ> & estimate'])->assertOk()->streamedContent());
            $off = app(ProjectExportData::class)->build($project, 'all', $locale);
            $this->assertSame([], $off['sections'][1]['tasks'][0]['attachments']);
            $this->assertStringNotContainsString($date, json_encode($off));
        }
    }

    public function test_section_page_breaks_only_precede_exported_sections_after_the_first(): void
    {
        $project = $this->project();
        $fields = array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), false);
        foreach ([false, true] as $breaks) {
            $data = app(ProjectExportData::class)->build($project, 'all', 'en', $fields, ['pageBreakBySection' => $breaks]);
            $source = view('exports.specification', ['document' => $data])->render();
            $this->assertSame($breaks ? 1 : 0, substr_count($source, 'class="section-page-break"'));
            $payload = [...$this->payload('docx', 'en'), 'fields' => $fields, 'pageBreakBySection' => $breaks];
            $xml = $this->xml($this->postJson('/api/admin/projects/'.$project->uuid.'/export', $payload)->assertOk()->streamedContent());
            $xpath = $this->documentXPath($xml);
            $this->assertSame($breaks ? 1 : 0, $xpath->query('//w:p[w:pPr/w:pStyle/@w:val="SectionStart"]')->length);
            $this->assertSame(0, $xpath->query('//w:br[@w:type="page"]')->length, 'Heading page-break-before avoids boundary/trailing blank pages.');
            if ($breaks) {
                $this->assertSame('2. Каталог', $xpath->query('//w:p[w:pPr/w:pStyle/@w:val="SectionStart"]')->item(0)->textContent);
                $this->assertStringContainsString('<w:pageBreakBefore', $xml);
            }
            $bytes = $this->postJson('/api/admin/projects/'.$project->uuid.'/export', [...$payload, 'format' => 'pdf'])->assertOk()->streamedContent();
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertSame($breaks ? 2 : 1, preg_match_all('/\/Type\s*\/Page\b/', $bytes));
        }
        $project->tasks()->where('project_section_id', $project->briefSections->first()->id)->update(['client_approved' => false]);
        $data = app(ProjectExportData::class)->build($project, 'approved', 'en', $fields, ['pageBreakBySection' => true]);
        $this->assertSame(2, $data['sections'][0]['number']);
        $this->assertStringNotContainsString('class="section-page-break"', view('exports.specification', ['document' => $data])->render());
    }

    public function test_new_export_options_are_strict_and_title_fallback_is_localized(): void
    {
        $project = $this->project();
        $url = '/api/admin/projects/'.$project->uuid.'/export';
        foreach ([['documentTitle' => str_repeat('x', 161)], ['documentTitle' => ['title' => 'Nested']], ['documentTitle' => false], ['documentTitle' => 0], ['documentTitle' => "Good\nBad"], ['documentTitle' => "Good\0Bad"], ['documentTitle' => "Good\u{202E}Bad"], ['pageBreakBySection' => 'true'], ['pageBreakBySection' => null], ['pageBreakBySection' => ['enabled' => true]], ['fields' => ['attachments' => ['enabled' => true]]], ['fields' => ['approvalDate' => 1]], ['fields' => ['pageBreakBySection' => true]], ['presentation' => ['unknown' => true]], ['totals' => ['price' => 1]]] as $invalid) {
            $this->postJson($url, [...$this->payload(), ...$invalid])->assertUnprocessable();
        }
        foreach (['ru', 'en'] as $locale) {
            foreach (['', '  ', null, "\u{00A0}"] as $blank) {
                $response = $this->postJson($url, [...$this->payload('docx', $locale), 'documentTitle' => $blank])->assertOk();
                $this->assertStringContainsString(trans('exports.title', [], $locale), $this->xml($response->streamedContent()));
            }
        }
        $this->assertSame('0', app(ProjectExportData::class)->build($project, 'all', 'en', [], ['documentTitle' => '0'])['title']);
        $this->assertSame(str_repeat('А', 160), app(ProjectExportData::class)->build($project, 'all', 'en', [], ['documentTitle' => str_repeat('А', 160)])['title']);
    }

    public function test_completed_scope_uses_done_status_and_its_own_totals_and_approval_count(): void
    {
        $project = $this->project();
        $project->tasks()->where('title', 'Первая задача')->update(['status' => 'done', 'estimate_hours' => 6, 'price' => 18000]);
        $project->tasks()->where('title', 'Вторая задача')->update(['status' => 'done', 'estimate_hours' => 0, 'price' => 0]);
        $fields = ['estimateHours' => true, 'price' => true];
        $data = app(ProjectExportData::class)->build($project, 'done', 'en', $fields);
        $this->assertSame(2, $data['totals']['taskCount']);
        $this->assertSame(1, $data['totals']['approvedCount']);
        $this->assertSame('6.00', $data['totals']['estimateHours']['value']);
        $this->assertSame('18000.00', $data['totals']['price']['value']);
        $this->assertSame(['1.1', '1.2'], array_column($data['sections'][0]['tasks'], 'number'));
        $this->assertCount(1, $data['sections']);
        $this->assertNull($data['totals']['estimateHours']['coverage']);
        $url = '/api/admin/projects/'.$project->uuid.'/export';
        foreach (['pdf', 'docx'] as $format) {
            $response = $this->postJson($url, [...$this->payload($format, 'en'), 'scope' => 'done', 'fields' => $fields])->assertOk();
            if ($format === 'docx') {
                $xml = $this->xml($response->streamedContent());
                $this->assertStringNotContainsString('Каталог товаров', $xml);
                $this->assertStringContainsString('Tasks: 2', $xml);
                $this->assertStringContainsString('Approved: 1', $xml);
                $this->assertStringContainsString("Total price: 18,000\u{00A0}₽", $xml);
            } else {
                $this->assertStringStartsWith('%PDF-', $response->streamedContent());
            }
        }
        $project->tasks()->where('status', 'done')->update(['status' => 'new']);
        foreach (['ru', 'en'] as $locale) {
            $this->postJson($url, [...$this->payload('pdf', $locale), 'scope' => 'done'])->assertUnprocessable()->assertJsonPath('errors.scope.0', trans('exports.empty', [], $locale));
        }
    }
}
