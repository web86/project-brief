<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

class ProjectExportData
{
    public const DEFAULT_FIELDS = [
        'description' => true, 'expectedResult' => true, 'priority' => true,
        'status' => true, 'estimateHours' => false, 'price' => false,
    ];

    /**
     * Build an explicit public-document allowlist; Eloquent models never reach renderers.
     *
     * @return array{project: array{name: string, website: ?string}, generated: array{date: string, isoDate: string, locale: string}, labels: array<string, string>, sections: array<array{number: int, name: string, tasks: array<array{number: ?string, title: string, content: array<array{label: string, value: string, block: bool}>}>}>}
     */
    public function build(Project $project, string $scope, string $locale, array $fields = []): array
    {
        $fields = array_replace(self::DEFAULT_FIELDS, $fields);
        $labels = trans('exports', [], $locale);
        $generatedAt = now()->locale($locale);
        $date = $generatedAt->isoFormat('D MMMM YYYY');
        $columns = ['id', 'project_id', 'project_section_id', 'position', 'title'];
        $source = ['description' => 'description', 'expectedResult' => 'expected_result', 'priority' => 'priority', 'status' => 'status', 'estimateHours' => 'estimate_hours', 'price' => 'price'];
        foreach ($source as $key => $column) {
            if ($fields[$key]) {
                $columns[] = $column;
            }
        }
        $tasks = $project->tasks()->select($columns)
            ->when($scope === 'approved', fn ($query) => $query->where('client_approved', true))
            ->orderBy('position')->orderBy('id')->get()->groupBy('project_section_id');
        $sections = [];
        foreach ($project->briefSections()->get() as $section) {
            $items = [];
            foreach ($tasks->get($section->id, collect()) as $task) {
                $task->setRelation('projectSection', $section);
                $content = [];
                foreach ($source as $key => $column) {
                    if (! $fields[$key] || $task->$column === null || $task->$column === '') {
                        continue;
                    }
                    $value = match ($key) {
                        'priority' => $labels['priorities'][$task->$column] ?? $labels['unknown'],
                        'status' => $labels['statuses'][$task->$column] ?? $labels['unknown'],
                        'estimateHours' => (string) $task->$column.' '.$labels['hours'],
                        'price' => (string) $task->$column.' '.$project->currency,
                        default => (string) $task->$column,
                    };
                    $content[] = ['label' => $labels[$key], 'value' => $value, 'block' => in_array($key, ['description', 'expectedResult'], true)];
                }
                $items[] = ['number' => ProjectStructure::number($task), 'title' => $task->title, 'content' => $content];
            }
            if ($items !== []) {
                $sections[] = ['number' => $section->position, 'name' => $section->name, 'tasks' => $items];
            }
        }
        if ($sections === []) {
            throw ValidationException::withMessages(['scope' => $labels['empty']]);
        }

        return [
            'project' => ['name' => $project->title, 'website' => $project->website_url],
            'generated' => ['date' => $date, 'isoDate' => $generatedAt->toDateString(), 'locale' => $locale],
            'labels' => array_intersect_key($labels, array_flip(['title', 'project', 'website', 'generated', 'footer'])),
            'sections' => $sections,
        ];
    }
}
