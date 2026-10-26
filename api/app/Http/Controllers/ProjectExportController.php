<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectExportRequest;
use App\Models\Project;
use App\Services\DocxProjectExporter;
use App\Services\ProjectExportData;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectExportController extends Controller
{
    public function __invoke(ProjectExportRequest $request, Project $project, ProjectExportData $builder): StreamedResponse
    {
        $options = $request->validated();
        abort_unless($options['format'] === 'docx', 422);
        $document = $builder->build($project, $options['scope'], $options['locale'], $options['fields'] ?? [], [
            'documentTitle' => $options['documentTitle'] ?? null,
            'pageBreakBySection' => $options['pageBreakBySection'] ?? false,
        ], $request->attributes->has('project_client'));
        $format = $options['format'];
        $bytes = app(DocxProjectExporter::class)->render($document);
        $slug = substr(Str::slug($project->title), 0, 100) ?: 'project';
        $filename = $slug.'-specification-'.$document['generated']['isoDate'].'.'.$format;

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
