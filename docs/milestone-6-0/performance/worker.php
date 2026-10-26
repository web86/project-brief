<?php

use App\Models\Project;
use App\Models\Task;
use App\Services\DocxProjectExporter;
use App\Services\ExportFonts;
use App\Services\PdfProjectExporter;
use App\Services\ProjectExportData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

$started = hrtime(true);
$root = $argv[1];
$job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$resultPath = $argv[3];
if (! str_starts_with(basename($root), 'projectbrief-export-perf-') || realpath(getenv('DB_DATABASE')) !== realpath($root.'/benchmark.sqlite') || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Only the disposable benchmark application may be used.');
}
$record = ['job' => $job, 'status' => 'running', 'stage' => 'bootstrap', 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')];
$reserve = str_repeat('x', 1048576);
$finished = false;
register_shutdown_function(function () use (&$record, &$reserve, &$finished, $started, $resultPath): void {
    if ($finished) {
        return;
    }
    $reserve = null;
    $record['status'] = 'fatal';
    $error = error_get_last();
    $record['error'] = $error ? ['type' => $error['type'], 'message' => substr($error['message'], 0, 1000), 'file' => $error['file'], 'line' => $error['line']] : null;
    $record['elapsed_ms'] = (hrtime(true) - $started) / 1e6;
    $record['peak_php_bytes'] = memory_get_peak_usage(true);
    $usage = getrusage();
    $record['cpu_seconds'] = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    $record['os_maxrss'] = $usage['ru_maxrss'];
    file_put_contents($resultPath, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
});
require $root.'/app/vendor/autoload.php';
$app = require $root.'/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();

try {
    if (($job['mode'] ?? '') === 'font-cost') {
        $temporary = $root.'/font-cost-'.getmypid().'.docx';
        copy($root.'/samples/mixed-en.docx', $temporary);
        $record['before_embedding_php_bytes'] = memory_get_usage(true);
        $embeddingStart = hrtime(true);
        ExportFonts::embedDocx($temporary);
        $record['embedding_ms'] = (hrtime(true) - $embeddingStart) / 1e6;
        $record['embedding_peak_php_bytes'] = memory_get_peak_usage(true);
        $record['embedded_output_bytes'] = filesize($temporary);
        $bytes = file_get_contents($temporary);
        $record['peak_after_reading_output_bytes'] = memory_get_peak_usage(true);
        $record['os_maxrss'] = getrusage()['ru_maxrss'];
        $record['output_bytes'] = strlen($bytes);
        $record['peak_php_bytes'] = memory_get_peak_usage(true);
        $record['elapsed_ms'] = (hrtime(true) - $started) / 1e6;
        unlink($temporary);
        $record['status'] = 'ok';
    } elseif (($job['mode'] ?? '') === 'seed') {
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $fixtures = [];
        $latin = 'Keep the original customer requirement. Improve navigation, accessibility and product photographs. Çığ ışığı İpek — исходный текст. ';
        $chinese = '这是客户输入的原始中文需求，请保留原文，不要自动翻译。改进页面导航、产品图片与移动端体验。';
        foreach (['mixed', 'cjk'] as $profile) {
            $text = ($profile === 'cjk' ? $chinese : '').str_repeat($latin, 5);
            $description = mb_substr($text, 0, 300);
            foreach ([10, 100, 500] as $count) {
                $project = Project::factory()->create(['title' => 'Website / Çığ / исходный проект', 'currency' => 'USD', 'sections' => ['Navigation', 'Products', 'Account']]);
                $sections = $project->briefSections()->get();
                for ($index = 1; $index <= $count; $index++) {
                    $section = $sections[($index - 1) % 3];
                    $task = Task::factory()->for($project)->create([
                        'project_section_id' => $section->id, 'position' => intdiv($index - 1, 3) + 1,
                        'title' => 'Website improvement '.$index.' — İpek / задача', 'location' => '/products/'.$index,
                        'description' => $description,
                        'expected_result' => mb_substr(str_repeat('Navigation is clear, accessible and responsive. ', 4), 0, 100),
                        'developer_notes' => mb_substr(str_repeat('Preserve original content and existing access controls. ', 3), 0, 80),
                        'priority' => 'normal', 'status' => 'done', 'client_approved' => $index % 2 === 0,
                        'client_approved_at' => $index % 2 === 0 ? '2026-10-26 10:00:00' : null,
                        'estimate_hours' => '8.25', 'price' => '975.50',
                    ]);
                    $task->attachments()->create(['original_name' => 'requirements-'.$index.'.pdf', 'stored_name' => 'fixture-'.$index, 'mime_type' => 'application/pdf', 'size' => 1024, 'disk' => 'local', 'path' => 'not-a-real-file', 'uploaded_by_type' => 'admin']);
                }
                $fixtures[$profile.'-'.$count] = $project->id;
            }
        }
        file_put_contents($root.'/fixtures.json', json_encode($fixtures));
        $record['status'] = 'ok';
    } else {
        $record['bootstrap_ms'] = (hrtime(true) - $started) / 1e6;
        $fixtures = json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
        $record['stage'] = 'build';
        $buildStart = hrtime(true);
        $project = Project::findOrFail($fixtures[$job['profile'].'-'.$job['tasks']]);
        $fields = $job['fields'] ?? array_fill_keys(array_keys(ProjectExportData::DEFAULT_FIELDS), true);
        $document = app(ProjectExportData::class)->build($project, 'all', $job['locale'], $fields);
        $record['build_ms'] = (hrtime(true) - $buildStart) / 1e6;
        $record['task_count'] = $document['totals']['taskCount'];
        $record['cjk_needed'] = ExportFonts::needsCjk($document);
        $record['before_render_php_bytes'] = memory_get_usage(true);
        $record['stage'] = 'render';
        if (isset($job['barrier'])) {
            file_put_contents($job['barrier'].'.'.getmypid().'.ready', '1');
            while (! is_file($job['barrier'])) {
                usleep(1000);
            }
        }
        $renderStart = hrtime(true);
        $bytes = $job['format'] === 'pdf' ? app(PdfProjectExporter::class)->render($document) : app(DocxProjectExporter::class)->render($document);
        $record['render_ms'] = (hrtime(true) - $renderStart) / 1e6;
        $record['elapsed_ms'] = (hrtime(true) - $started) / 1e6;
        $record['output_bytes'] = strlen($bytes);
        $record['peak_php_bytes'] = memory_get_peak_usage(true);
        $record['after_render_php_bytes'] = memory_get_usage(true);
        $usage = getrusage();
        $record['cpu_seconds'] = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
        $record['os_maxrss'] = $usage['ru_maxrss'];
        if (isset($job['output'])) {
            file_put_contents($job['output'], $bytes);
        }
        $record['status'] = 'ok';
        $record['stage'] = 'complete';
    }
} catch (Throwable $error) {
    $record['status'] = 'exception';
    $record['error'] = ['type' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()];
    $record['elapsed_ms'] = (hrtime(true) - $started) / 1e6;
    $record['peak_php_bytes'] = memory_get_peak_usage(true);
}
file_put_contents($resultPath, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT));
$finished = true;
