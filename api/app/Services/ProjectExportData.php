<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

class ProjectExportData
{
    public const CLIENT_FIELDS = ['description', 'expectedResult', 'priority', 'status', 'attachments', 'approvalDate'];

    public const DEFAULT_FIELDS = [
        'description' => true, 'expectedResult' => true, 'priority' => true,
        'status' => true, 'estimateHours' => false, 'price' => false,
        'attachments' => false, 'approvalDate' => false, 'developerNotes' => false,
    ];

    /**
     * Build an explicit document allowlist; models and private identifiers never reach renderers.
     *
     * @return array{title: string, project: array{name: string, website: ?string}, generated: array{date: string, isoDate: string, locale: string}, labels: array<string, string>, sections: array, totals: array, summary: array, presentation: array{pageBreakBySection: bool}}
     */
    public function build(Project $project, string $scope, string $locale, array $fields = [], array $options = [], bool $client = false, ?int $maxTasks = null, ?int $maxTextBytes = null): array
    {
        $locale = SupportedLocales::resolve($locale);
        $fields = array_replace(self::DEFAULT_FIELDS, $fields);
        if ($client) {
            $fields = array_intersect_key($fields, array_flip(self::CLIENT_FIELDS)) + array_fill_keys(array_keys(self::DEFAULT_FIELDS), false);
        }
        $labels = trans('exports', [], $locale);
        $generatedAt = now()->locale($locale);
        $title = preg_replace('/^\s+|\s+$/u', '', $options['documentTitle'] ?? '');
        $title = $title !== '' ? $title : $labels['title'];
        $columns = ['id', 'project_id', 'project_section_id', 'position', 'title', 'client_approved'];
        $source = ['description' => 'description', 'expectedResult' => 'expected_result', 'priority' => 'priority', 'status' => 'status', 'estimateHours' => 'estimate_hours', 'price' => 'price', 'developerNotes' => 'developer_notes'];
        foreach ($source as $key => $column) {
            if ($fields[$key]) {
                $columns[] = $column;
            }
        }
        if ($fields['approvalDate']) {
            $columns[] = 'client_approved_at';
        }
        $query = $project->tasks()->select($columns)
            ->when($fields['attachments'], fn ($query) => $query->with(['attachments:id,task_id,original_name']))
            ->when($scope === 'approved', fn ($query) => $query->where('client_approved', true))
            ->when($scope === 'done', fn ($query) => $query->where('status', 'done'))
            ->orderBy('position')->orderBy('id');
        if ($maxTasks !== null && (clone $query)->count() > $maxTasks) {
            throw ValidationException::withMessages(['scope' => $labels['tooLarge']])->status(413);
        }
        if ($maxTextBytes !== null) {
            $connection = $project->getConnection();
            $textColumns = array_values(array_intersect($columns, ['title', 'description', 'expected_result', 'developer_notes']));
            $lengths = array_map(function (string $column) use ($connection): string {
                $value = 'COALESCE('.$connection->getQueryGrammar()->wrap($column).", '')";

                return match ($connection->getDriverName()) {
                    'sqlite' => 'LENGTH(CAST('.$value.' AS BLOB))',
                    'sqlsrv' => 'DATALENGTH('.$value.')',
                    default => 'OCTET_LENGTH('.$value.')',
                };
            }, $textColumns);
            $bytes = (clone $query)->setEagerLoads([])->reorder()->select([])
                ->selectRaw('COALESCE(SUM('.implode(' + ', $lengths).'), 0) AS export_bytes')->toBase()->value('export_bytes');
            if ($bytes > $maxTextBytes) {
                throw ValidationException::withMessages(['scope' => $labels['tooLarge']])->status(413);
            }
        }
        $tasks = $query->when($maxTasks !== null, fn ($query) => $query->limit($maxTasks + 1))->get();
        if ($maxTasks !== null && $tasks->count() > $maxTasks) {
            throw ValidationException::withMessages(['scope' => $labels['tooLarge']])->status(413);
        }
        $tasks = $tasks->groupBy('project_section_id');
        $sections = [];
        $totals = ['taskCount' => 0, 'approvedCount' => 0];
        $workflowCounts = ['workflowApprovedCount' => 'approved', 'clarificationCount' => 'clarification', 'doneCount' => 'done'];
        if ($fields['status']) {
            $totals += array_fill_keys(array_keys($workflowCounts), 0);
        }
        foreach (['estimateHours', 'price'] as $key) {
            if ($fields[$key]) {
                $totals[$key] = ['minorUnits' => '0', 'providedTaskCount' => 0];
            }
        }
        foreach ($project->briefSections()->get() as $section) {
            $items = [];
            foreach ($tasks->get($section->id, collect()) as $task) {
                $task->setRelation('projectSection', $section);
                $totals['taskCount']++;
                $totals['approvedCount'] += (int) $task->client_approved;
                if ($fields['status']) {
                    foreach ($workflowCounts as $key => $status) {
                        $totals[$key] += (int) ($task->status === $status);
                    }
                }
                $content = [];
                foreach ($source as $key => $column) {
                    if (! $fields[$key] || $task->$column === null || trim((string) $task->$column) === '') {
                        continue;
                    }
                    $value = match ($key) {
                        'priority' => $labels['priorities'][$task->$column] ?? $labels['unknown'],
                        'status' => $labels['statuses'][$task->$column] ?? $labels['unknown'],
                        'estimateHours' => $this->formatAmount($this->minorUnits($task->getRawOriginal($column)), $locale).' '.$labels['hours'],
                        'price' => $this->money($this->minorUnits($task->getRawOriginal($column)), $project->currency, $locale),
                        default => (string) $task->$column,
                    };
                    if (isset($totals[$key])) {
                        $totals[$key]['minorUnits'] = $this->addMinorUnits($totals[$key]['minorUnits'], $this->minorUnits($task->getRawOriginal($column)));
                        $totals[$key]['providedTaskCount']++;
                    }
                    $content[] = ['key' => $key, 'label' => $labels[$key], 'value' => $value, 'block' => in_array($key, ['description', 'expectedResult', 'developerNotes'], true)];
                }
                if ($fields['approvalDate'] && $task->client_approved && $task->client_approved_at) {
                    $content[] = ['key' => 'approvalDate', 'label' => $labels['approvedOn'], 'value' => $task->client_approved_at->locale($locale)->isoFormat(SupportedLocales::settings($locale)['approval_date']), 'block' => false];
                }
                $filenames = [];
                if ($fields['attachments']) {
                    foreach ($task->attachments->whereNull('comment_id') as $attachment) {
                        $filename = $this->safeFilename($attachment->original_name);
                        if ($filename !== '') {
                            $filenames[] = $filename;
                        }
                    }
                }
                $byKey = array_column($content, null, 'key');
                $metadata = [];
                foreach (['status', 'priority', 'estimateHours', 'price', 'approvalDate'] as $key) {
                    if (isset($byKey[$key])) {
                        $field = $byKey[$key];
                        $metadata[] = (in_array($key, ['priority', 'approvalDate'], true) ? $field['label'].': ' : '').$field['value'];
                    }
                }
                $items[] = ['number' => ProjectStructure::number($task), 'title' => $task->title, 'content' => $content, 'metadata' => implode(' · ', $metadata), 'attachments' => $filenames];
            }
            if ($items !== []) {
                $sections[] = ['number' => $section->position, 'name' => $section->name, 'tasks' => $items];
            }
        }
        if ($sections === []) {
            throw ValidationException::withMessages(['scope' => $labels['empty']]);
        }
        $summary = [['label' => $labels['taskCount'], 'value' => (string) $totals['taskCount'], 'coverage' => null]];
        foreach (array_keys($workflowCounts) as $key) {
            if (($totals[$key] ?? 0) > 0) {
                $summary[] = ['label' => $labels[$key], 'value' => (string) $totals[$key], 'coverage' => null];
            }
        }
        $summary[] = ['label' => $labels['approvedCount'], 'value' => (string) $totals['approvedCount'], 'coverage' => null];
        foreach (['estimateHours', 'price'] as $key) {
            if (! isset($totals[$key])) {
                continue;
            }
            $total = &$totals[$key];
            $total['value'] = substr(str_pad($total['minorUnits'], 3, '0', STR_PAD_LEFT), 0, -2).'.'.substr(str_pad($total['minorUnits'], 3, '0', STR_PAD_LEFT), -2);
            $total['formatted'] = $key === 'price' ? $this->money($total['minorUnits'], $project->currency, $locale) : $this->formatAmount($total['minorUnits'], $locale).' '.$labels['hours'];
            if ($key === 'price' && $total['providedTaskCount'] === 0) {
                $total['formatted'] = $labels['notCalculated'];
            }
            $total['coverage'] = $total['providedTaskCount'] < $totals['taskCount']
                ? trans_choice('exports.'.$key.'Coverage', $totals['taskCount'], ['provided' => $total['providedTaskCount'], 'total' => $totals['taskCount']], $locale) : null;
            $summary[] = ['label' => $labels[$key.'Total'], 'value' => $total['formatted'], 'coverage' => $total['coverage']];
            unset($total['minorUnits'], $total);
        }

        return [
            'branding' => app(WorkspaceBranding::class)->document($project->workspace, $locale, $options['preferVectorLogo'] ?? false),
            'title' => $title,
            'project' => ['name' => $project->title, 'website' => $project->website_url],
            'generated' => ['date' => $generatedAt->isoFormat(SupportedLocales::settings($locale)['generated_date'] ?? 'D MMMM YYYY'), 'isoDate' => $generatedAt->toDateString(), 'locale' => $locale],
            'labels' => array_intersect_key($labels, array_flip(['title', 'project', 'website', 'generated', 'footer', 'attachments', 'summary'])),
            'sections' => $sections,
            'totals' => $totals,
            'summary' => $summary,
            'presentation' => ['pageBreakBySection' => $options['pageBreakBySection'] ?? false],
        ];
    }

    /** Preserve database decimal precision; SQLite numeric values may require decimal normalization. */
    private function minorUnits(string|float|int $value): string
    {
        $decimal = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $decimal)) {
            $decimal = number_format((float) $value, 2, '.', '');
        }
        [$integer, $fraction] = array_pad(explode('.', $decimal), 2, '');

        return ltrim($integer.str_pad($fraction, 2, '0'), '0') ?: '0';
    }

    /** Add decimal minor units without binary rounding or an aggregate PHP integer limit. */
    private function addMinorUnits(string $left, string $right): string
    {
        $result = '';
        $carry = 0;
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $left[$i] : 0) + ($j >= 0 ? (int) $right[$j] : 0) + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $result;
    }

    private function formatAmount(string $minorUnits, string $locale): string
    {
        $digits = str_pad($minorUnits, 3, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($digits, -2), '0');
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', SupportedLocales::settings($locale)['group'], substr($digits, 0, -2));

        return $integer.($fraction !== '' ? SupportedLocales::settings($locale)['decimal'].$fraction : '');
    }

    private function money(string $minorUnits, string $currency, string $locale): string
    {
        $symbol = ['RUB' => '₽', 'USD' => '$', 'EUR' => '€', 'TRY' => '₺'][$currency] ?? $currency;

        return $this->formatAmount($minorUnits, $locale)."\u{00A0}".$symbol;
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $filename);
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = trim(mb_substr($filename, 0, 200));

        return in_array($filename, ['', '.', '..'], true) ? '' : $filename;
    }
}
