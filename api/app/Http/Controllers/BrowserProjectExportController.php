<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectExportRequest;
use App\Models\Project;
use App\Services\ProjectExportData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BrowserProjectExportController extends Controller
{
    public function __invoke(ProjectExportRequest $request, Project $project, ProjectExportData $builder): JsonResponse|Response
    {
        abort_unless(config('browser_pdf.enabled'), 404);
        $started = hrtime(true);
        $options = $request->validated();
        abort_unless($options['format'] === 'pdf', 422);
        $document = $builder->build($project, $options['scope'], $options['locale'], $options['fields'] ?? [], [
            'documentTitle' => $options['documentTitle'] ?? null,
            'pageBreakBySection' => $options['pageBreakBySection'] ?? false,
        ], $request->attributes->has('project_client'), config('browser_pdf.max_tasks'), config('browser_pdf.max_text_bytes'));
        $slug = substr(Str::slug($project->title), 0, 100) ?: 'project';
        $json = json_encode([
            'schemaVersion' => 1,
            'filename' => $slug.'-specification-'.$document['generated']['isoDate'].'.pdf',
            'document' => $document,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
        if (strlen($json) > config('browser_pdf.max_json_bytes')) {
            return response()->json(['code' => 'export_too_large', 'message' => trans('exports.tooLarge', [], $options['locale'])], 413, $headers);
        }
        if (config('browser_pdf.diagnostics') && app()->environment('testing')) {
            $headers['Server-Timing'] = 'export-json;dur='.round((hrtime(true) - $started) / 1e6, 2);
            $headers['X-Benchmark-PHP-Peak'] = (string) memory_get_peak_usage(true);
        }

        return response($json, 200, ['Content-Type' => 'application/json; charset=UTF-8', ...$headers]);
    }
}
