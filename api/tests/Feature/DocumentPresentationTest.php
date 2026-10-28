<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Services\BrandImage;
use App\Services\DocxProjectExporter;
use App\Services\ProjectExportData;
use App\Services\WorkspaceBranding;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(int $count, string $locale, bool $brand = true, bool $long = false): Project
    {
        Storage::fake('local');
        $names = match ($locale) {
            'ru' => ['Главная', 'Каталог и поиск', 'Оформление заказа'],
            'tr' => ['Ana sayfa', 'Ürün kataloğu', 'Müşteri hesabı'],
            'zh-CN' => ['首页', '产品目录与搜索', '客户账户'],
            default => ['Home page', 'Catalogue and search', 'Customer account'],
        };
        $project = Project::factory()->create(['title' => 'Northline — Website specification', 'website_url' => 'https://northline.example.test', 'currency' => 'USD', 'sections' => $names]);
        $sections = $project->briefSections;
        for ($index = 1; $index <= $count; $index++) {
            $title = match ($locale) {
                'ru' => 'Обновить блок «Мы в цифрах» и адаптивную навигацию',
                'tr' => 'Çığ İpek — ürün aramasını ve mobil gezinmeyi düzenle',
                'zh-CN' => '优化产品搜索和移动导航，保留原始需求与用户输入',
                default => 'Refine the product search and responsive navigation',
            };
            $paragraph = match ($locale) {
                'ru' => 'Сохранить исходный порядок элементов и понятные подписи. Поиск должен работать с клавиатуры и учитывать выбранный раздел.',
                'tr' => 'Özgün içerik ve sıralama korunmalıdır. Arama klavye ile kullanılmalı; Türkçe karakterler doğru gösterilmelidir.',
                'zh-CN' => '保留原始内容和排序。搜索需要支持键盘操作，明确显示当前筛选条件，并在移动设备上保持可读性。',
                default => 'Preserve the original content and order. Search must support keyboard navigation and clearly display the selected category.',
            };
            $description = $paragraph.' [REQ-'.$index.']';
            if ($long && $index === 3) {
                $title .= ' — '.str_repeat($title.' ', 5);
                $description = implode("\n\n", array_map(fn ($line) => '['.$line.'] '.$paragraph.' https://example.test/specification/'.str_repeat('long-path-', 12), range(1, 40)))."\nEND-LONG-REQUIREMENT";
            }
            $section = $sections[min(2, intdiv(($index - 1) * 3, $count))];
            $task = Task::factory()->for($project)->create([
                'project_section_id' => $section->id, 'position' => $index,
                'title' => $title.' #'.$index, 'description' => $description,
                'expected_result' => $index % 3 === 0 ? $paragraph.' [RESULT-'.$index.']' : null,
                'developer_notes' => $index % 4 === 0 ? "Use the existing API; preserve access rules.\nKeep literal <tags> & original punctuation. [NOTE-".$index.']' : null,
                'priority' => $index % 5 === 0 ? 'high' : 'normal',
                'status' => $index <= 9 && $count === 11 ? 'approved' : ($index % 4 === 0 ? 'done' : ($index % 5 === 0 ? 'clarification' : 'in_progress')),
                'client_approved' => $count === 11 ? false : $index % 3 === 0,
                'client_approved_at' => $count !== 11 && $index % 3 === 0 ? '2026-10-20 09:00:00' : null,
                'estimate_hours' => $index === $count ? null : ($index % 5 === 0 ? '0.00' : '2.50'),
                'price' => $count === 11 || $index === $count ? null : ($index % 5 === 0 ? '0.00' : '150.25'),
            ]);
            if ($index % 6 === 0) {
                $task->attachments()->create(['original_name' => 'requirements-'.$index.'-Çığ-中文.pdf', 'stored_name' => 'test-only', 'mime_type' => 'application/pdf', 'size' => 1, 'disk' => 'local', 'path' => 'not-public', 'uploaded_by_type' => 'admin']);
            }
        }
        if ($brand) {
            $image = imagecreatetruecolor(1024, 320);
            for ($y = 0; $y < 320; $y++) {
                for ($x = 0; $x < 1024; $x++) {
                    imagesetpixel($image, $x, $y, (($x * 73 + $y * 41) % 120 + 100) << 16 | (($x * 17 + $y * 83) % 120 + 100) << 8 | (($x * 37 + $y * 13) % 120 + 100));
                }
            }
            imagefilledrectangle($image, 20, 20, 300, 300, imagecolorallocate($image, 23, 105, 93));
            imagestring($image, 5, 155, 155, 'N', imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagepng($image);
            $png = ob_get_clean();
            imagedestroy($image);
            Storage::disk('local')->put('branding/test-logo.png', $png);
            $settings = app(WorkspaceBranding::class)->settings($project->workspace);
            $settings['assets']['logo'] = ['path' => 'branding/test-logo.png', 'token' => 'synthetic'];
            $settings['visual'] = ['companyName' => 'Northline Digital Studio', 'primaryColor' => '#17695d'];
            $settings['contact'] = array_replace($settings['contact'], ['city' => 'İstanbul', 'address' => 'Example Street 14', 'email' => 'studio@example.test', 'website' => 'https://studio.example.test']);
            $settings['business'] = array_replace($settings['business'], ['legalName' => 'Northline Digital Ltd', 'taxId' => 'TEST-1234567890', 'payee' => 'Northline Digital Ltd', 'bankName' => 'Example International Bank', 'account' => 'TR0000000000000000000000000000000000000000000000000000000000000000', 'bic' => 'EXAMPLEXX', 'note' => $long ? str_repeat('Full additional payment instructions must remain intact. ', 28).'END-BUSINESS-NOTE' : 'Payment reference: project specification.']);
            $settings['export'] = array_fill_keys(array_keys(WorkspaceBranding::EXPORT_DEFAULTS), true);
            $project->workspace->update(['branding' => $settings]);
        }

        return $project->fresh();
    }

    public function test_reproducible_document_matrix_preserves_scope_content_and_generates_real_docx(): void
    {
        gc_collect_cycles();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $cases = ['small-en' => [8, 'en'], 'studio-ru' => [11, 'ru'], 'medium-ru' => [75, 'ru'], 'large-en' => [500, 'en'], 'long-tr' => [8, 'tr', true, true], 'chinese' => [10, 'zh-CN'], 'section-breaks' => [11, 'ru'], 'minimal' => [8, 'en', false]];
        $directory = getenv('DOCUMENT_EXPORT_QA_DIRECTORY');
        if ($directory && ! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $metrics = [];
        foreach ($cases as $name => $arguments) {
            $project = $this->fixture(...$arguments);
            $fields = array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), true);
            if ($name === 'minimal') {
                $fields = array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), false);
            }
            $started = microtime(true);
            $document = app(ProjectExportData::class)->build($project, 'all', $arguments[1], $fields, ['pageBreakBySection' => $name === 'section-breaks']);
            $this->assertSame($arguments[0], $document['totals']['taskCount']);
            $this->assertSame($arguments[0], array_sum(array_map(fn ($section) => count($section['tasks']), $document['sections'])));
            $bytes = app(DocxProjectExporter::class)->render($document);
            $this->assertStringStartsWith('PK', $bytes);
            $metrics[$name] = ['tasks' => $arguments[0], 'docxBytes' => strlen($bytes), 'docxAndDataMs' => round((microtime(true) - $started) * 1000, 1)];
            if ($directory) {
                file_put_contents($directory.'/'.$name.'.json', json_encode(['schemaVersion' => 1, 'filename' => $name.'.pdf', 'document' => $document], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                file_put_contents($directory.'/'.$name.'.docx', $bytes);
            }
            unset($bytes, $document, $project);
            gc_collect_cycles();
        }
        if ($directory) {
            file_put_contents($directory.'/docx-metrics.json', json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_workflow_status_client_confirmation_and_missing_prices_remain_distinct(): void
    {
        $project = $this->fixture(11, 'ru', false);
        $builder = app(ProjectExportData::class);
        foreach (['ru', 'en', 'tr', 'zh-CN'] as $locale) {
            $data = $builder->build($project, 'all', $locale, ['price' => true, 'estimateHours' => true]);
            $this->assertSame(9, $data['totals']['workflowApprovedCount']);
            $this->assertSame(0, $data['totals']['approvedCount']);
            $this->assertSame(0, $data['totals']['price']['providedTaskCount']);
            $this->assertSame(trans('exports.notCalculated', [], $locale), $data['totals']['price']['formatted']);
            $summary = array_column($data['summary'], 'value', 'label');
            $this->assertSame('9', $summary[trans('exports.workflowApprovedCount', [], $locale)]);
            $this->assertSame('0', $summary[trans('exports.approvedCount', [], $locale)]);
        }
        $project->tasks()->update(['price' => '0.00']);
        $data = $builder->build($project, 'all', 'en', ['price' => true]);
        $this->assertSame("0\u{00A0}$", $data['totals']['price']['formatted']);
        $this->assertNull($data['totals']['price']['coverage']);
        $project->tasks()->orderBy('id')->first()->update(['price' => null]);
        $partial = $builder->build($project, 'all', 'en', ['price' => true]);
        $this->assertSame(10, $partial['totals']['price']['providedTaskCount']);
        $this->assertSame('Price provided for 10 of 11 tasks', $partial['totals']['price']['coverage']);
    }

    public function test_metadata_visibility_and_scope_totals_follow_existing_options_in_every_combination(): void
    {
        $project = $this->fixture(8, 'en', false);
        $builder = app(ProjectExportData::class);
        for ($mask = 0; $mask < 16; $mask++) {
            $fields = array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), false);
            foreach (['status', 'priority', 'estimateHours', 'price'] as $bit => $key) {
                $fields[$key] = (bool) ($mask & (1 << $bit));
            }
            foreach (['all', 'approved', 'done'] as $scope) {
                $data = $builder->build($project, $scope, 'en', $fields);
                $tasks = $project->tasks()->when($scope === 'approved', fn ($query) => $query->where('client_approved', true))->when($scope === 'done', fn ($query) => $query->where('status', 'done'))->get();
                $this->assertSame($tasks->count(), $data['totals']['taskCount']);
                $this->assertSame($tasks->where('client_approved', true)->count(), $data['totals']['approvedCount']);
                foreach (['price', 'estimateHours'] as $key) {
                    $this->assertSame($fields[$key], isset($data['totals'][$key]));
                }
                $this->assertSame($fields['status'], isset($data['totals']['workflowApprovedCount']));
                foreach ($data['sections'] as $section) {
                    foreach ($section['tasks'] as $task) {
                        $keys = array_column($task['content'], 'key');
                        foreach ($fields as $key => $enabled) {
                            if (! $enabled) {
                                $this->assertNotContains($key, $keys);
                            }
                        }
                        if ($fields['priority']) {
                            $this->assertStringContainsString('Priority:', $task['metadata']);
                        }
                        $this->assertStringNotContainsString('Status:', $task['metadata']);
                        $this->assertStringNotContainsString('Estimate:', $task['metadata']);
                        $this->assertStringNotContainsString('Price:', $task['metadata']);
                    }
                }
            }
        }
        $fields = array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), true);
        $data = $builder->build($project, 'all', 'en', $fields, [], true);
        $serialized = json_encode($data);
        foreach (['developerNotes', 'estimateHours', 'price', 'not-public'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_docx_uses_borderless_wrapping_tables_and_preserves_original_paragraphs(): void
    {
        $project = $this->fixture(8, 'tr', true, true);
        $document = app(ProjectExportData::class)->build($project, 'all', 'tr', array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), true));
        $bytes = app(DocxProjectExporter::class)->render($document);
        $file = tempnam(sys_get_temp_dir(), 'document-style-');
        try {
            file_put_contents($file, $bytes);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($file));
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML($zip->getFromName('word/document.xml')));
            $xpath = new \DOMXPath($dom);
            $paragraphs = array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('//w:p')));
            $text = implode("\n", $paragraphs);
            foreach (['END-LONG-REQUIREMENT', 'END-BUSINESS-NOTE', 'Çığ İpek', 'Keep literal <tags> & original punctuation.', '[REQ-8]', '[RESULT-6]', '[NOTE-4]'] as $original) {
                $this->assertStringContainsString($original, $text);
            }
            $this->assertNotContains('Açıklama', $paragraphs);
            $this->assertSame(0, $xpath->query('//w:tblBorders|//w:noWrap')->length);
            $this->assertSame('964', $xpath->evaluate('string(//w:pgMar/@w:left)'));
            $this->assertSame(1, $xpath->query('//w:p[contains(., "[7] Özgün")]/w:pPr/w:keepLines')->length);
            $this->assertSame(0, $xpath->query('//w:p[contains(., "END-BUSINESS-NOTE")]/w:pPr/w:keepLines')->length);
            $footer = $zip->getFromName('word/footer1.xml');
            $this->assertStringNotContainsString('TR000000', $footer);
            $this->assertStringNotContainsString('Example Street', $footer);
            $zip->close();
        } finally {
            unlink($file);
        }
    }

    public function test_pdf_uses_only_safe_shape_svg_and_docx_retains_print_png_with_visibility_guards(): void
    {
        $project = $this->fixture(5, 'en');
        $this->actingAs($this->ownerFor($project));
        $workspace = $project->workspace;
        $settings = $workspace->branding;
        $settings['assets']['logo']['svgPath'] = 'branding/logo.svg';
        $settings['visual']['primaryColor'] = '#ffffff';
        Storage::disk('local')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 30" onload="bad()"><script>bad()</script><rect width="100" height="30" fill="#17695d"/></svg>');
        $workspace->update(['branding' => $settings]);
        $url = '/api/admin/projects/'.$project->uuid.'/export-data';
        $options = ['format' => 'pdf', 'scope' => 'all', 'locale' => 'en'];
        $legacy = $this->postJson($url, $options)->assertOk();
        $this->assertNull($legacy->json('document.branding.logoSvg'));
        $this->assertStringStartsWith('data:image/png;base64,', $legacy->json('document.branding.logo'));
        $this->withHeader('X-ProjectBrief-Pdf-Renderer', '2');
        $response = $this->postJson($url, $options)->assertOk();
        $this->assertNull($response->json('document.branding.logo'));
        $this->assertStringContainsString('<rect', $response->json('document.branding.logoSvg'));
        $this->assertStringNotContainsString('<script', $response->json('document.branding.logoSvg'));
        $this->assertStringNotContainsString('onload', $response->json('document.branding.logoSvg'));
        $this->assertNotSame('#ffffff', $response->json('document.branding.color'));
        $service = app(WorkspaceBranding::class);
        $this->assertStringStartsWith('data:image/png;base64,', $service->document($workspace, 'en')['logo']);
        Storage::disk('local')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 30"><text x="0" y="20">中文 Çığ</text></svg>');
        $response = $this->postJson($url, $options)->assertOk();
        $this->assertNull($response->json('document.branding.logoSvg'));
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('document.branding.logo'));
        Storage::disk('local')->put('branding/logo.svg', '<s:svg xmlns:s="http://www.w3.org/2000/svg" viewBox="0 0 100 30"><s:rect width="100" height="30"/></s:svg>');
        $response = $this->postJson($url, $options)->assertOk();
        $this->assertNull($response->json('document.branding.logoSvg'));
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('document.branding.logo'));
        $settings['export'] = array_fill_keys(array_keys(WorkspaceBranding::EXPORT_DEFAULTS), false);
        $workspace->update(['branding' => $settings]);
        $response = $this->postJson($url, $options)->assertOk();
        $this->assertNull($response->json('document.branding.logoSvg'));
        $this->assertNull($response->json('document.branding.logo'));
        $this->assertSame([], $response->json('document.branding.business'));
        $this->assertStringNotContainsString('TEST-1234567890', $response->getContent());
    }

    public function test_print_raster_is_sized_for_the_header_preserves_alpha_and_never_grows(): void
    {
        $image = imagecreatetruecolor(720, 240);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $seed = 1729;
        for ($y = 40; $y < 200; $y++) {
            for ($x = 40; $x < 680; $x++) {
                $seed = ($seed * 1664525 + 1013904223) & 0xFFFFFFFF;
                imagesetpixel($image, $x, $y, ($seed >> 8) & 0xFFFFFF);
            }
        }
        ob_start();
        imagepng($image);
        $original = ob_get_clean();
        imagedestroy($image);
        $output = app(BrandImage::class)->documentPng($original);
        $size = getimagesizefromstring($output);
        $this->assertLessThanOrEqual(360, $size[0]);
        $this->assertLessThanOrEqual(117, $size[1]);
        $this->assertLessThan(strlen($original), strlen($output));
        $decoded = imagecreatefromstring($output);
        $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        imagedestroy($decoded);
        $small = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $this->assertSame($small, app(BrandImage::class)->documentPng($small));
    }
}
