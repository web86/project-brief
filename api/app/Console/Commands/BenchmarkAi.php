<?php

namespace App\Console\Commands;

use App\Services\AiTaskAssistant;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Sleep;

class BenchmarkAi extends Command
{
    public const SCENARIOS = [
        'simple' => 'Хочу, чтобы фотографии товаров были крупнее и их можно было листать на телефоне',
        'vague' => 'Сайт работает медленно, особенно каталог. Нужно сделать быстрее',
        'multiple' => 'Нужно сделать личный кабинет, историю заказов, онлайн-оплату и уведомления о доставке',
    ];

    protected $signature = 'ai:benchmark
        {--live : Send only the built-in synthetic scenarios to ZvenoAI}
        {--model=* : Exact free model ID; defaults to the three benchmark models}
        {--scenario=* : simple, vague or multiple; defaults to all three}
        {--format=json_schema : json_schema, prompt_json, or diagnostic-only json_object/text}
        {--delay=10 : Seconds between requests, from 10 to 60}
        {--output= : Optional JSON report file, created without overwriting an existing file}';

    protected $description = 'Compare three ZvenoAI free routes with the task assistant schema; never reads project data';

    public function handle(AiTaskAssistant $assistant): int
    {
        $models = array_values(array_unique($this->option('model') ?: AiTaskAssistant::BENCHMARK_MODELS));
        $scenarios = array_values(array_unique($this->option('scenario') ?: array_keys(self::SCENARIOS)));
        $delay = filter_var($this->option('delay'), FILTER_VALIDATE_INT);
        $format = $this->option('format');
        if (array_diff($models, AiTaskAssistant::BENCHMARK_MODELS) || array_diff($scenarios, array_keys(self::SCENARIOS)) || ! in_array($format, ['json_schema', 'prompt_json', 'json_object', 'text'], true) || $delay === false || $delay < 10 || $delay > 60) {
            $this->error('Use only the three exact free model IDs, built-in scenarios and delay 10..60.');

            return self::INVALID;
        }
        $output = $this->option('output');
        if ($output && (! is_dir(dirname($output)) || file_exists($output))) {
            $this->error('The output directory must exist and the output file must not already exist.');

            return self::INVALID;
        }
        if (! $this->option('live')) {
            $this->info('Plan only: '.count($models).' models × '.count($scenarios).' synthetic scenarios. Use --live to send requests. No network or database access.');

            return self::SUCCESS;
        }
        if (! config('ai.enabled') || trim((string) config('ai.providers.zveno.key')) === '') {
            $this->error('Enable AI and set ZVENO_API_KEY in backend .env. No live requests were sent.');

            return self::FAILURE;
        }
        $report = [
            'startedAt' => now('UTC')->toIso8601String(),
            'origin' => 'local CLI; not a Jino hosting measurement',
            'syntheticOnly' => true,
            'settings' => ['timeoutSeconds' => 25, 'connectTimeoutSeconds' => 5, 'maxCompletionTokens' => 6000, 'reasoningEffort' => $format === 'prompt_json' ? null : 'low', 'excludeReasoning' => $format === 'prompt_json' ? null : true, 'format' => $format, 'strictJsonSchema' => $format === 'json_schema', 'serverValidation' => true, 'diagnosticOnly' => in_array($format, ['json_object', 'text'], true), 'delaySeconds' => $delay, 'retries' => 0],
            'plannedRequests' => count($models) * count($scenarios),
            'catalog' => null, 'runs' => [],
            'expertReview' => 'Not scored automatically. Review Russian, expected result, questions and invented requirements manually.',
        ];
        try {
            $report['catalog'] = $assistant->benchmarkCatalog();
        } catch (HttpResponseException $exception) {
            $this->error('Catalog unavailable: '.json_decode($exception->getResponse()->getContent(), true)['code']);

            return self::FAILURE;
        }
        $stop = false;
        $attempts = 0;
        foreach ($models as $model) {
            $catalog = $report['catalog']['models'][$model];
            if (! $catalog['present'] || ! $catalog['free']) {
                $report['runs'][] = ['model' => $model, 'skipped' => 'Exact route absent or zero token prices not confirmed.'];
                $this->warn($model.': skipped (free route not confirmed).');

                continue;
            }
            foreach ($scenarios as $scenario) {
                if ($attempts++ > 0) {
                    Sleep::for($delay)->seconds();
                }
                $this->line($model.' / '.$scenario);
                $result = $assistant->benchmark($model, ['description' => self::SCENARIOS[$scenario]], $format);
                $report['runs'][] = ['model' => $model, 'scenario' => $scenario, 'input' => self::SCENARIOS[$scenario], ...$result];
                $jsonStatus = $result['jsonValid'] === null ? 'n/a' : ($result['jsonValid'] ? 'valid' : 'invalid');
                $schemaStatus = $result['schemaValid'] === null ? 'n/a' : ($result['schemaValid'] ? 'valid' : 'invalid');
                $this->line('HTTP '.($result['httpStatus'] ?? 'none').' | '.$result['elapsedMs'].' ms | JSON '.$jsonStatus.' | schema '.$schemaStatus.' | '.($result['error'] ?? 'ok'));
                if (in_array($result['httpStatus'], [401, 402, 403, 429], true)) {
                    $this->warn('Stopping this run after an account/rate-limit response; no retries or model fallback.');
                    $stop = true;
                    break;
                }
                if ($result['httpStatus'] === 400) {
                    $this->warn('Skipping remaining scenarios for this model after HTTP 400; no automatic format changes.');
                    break;
                }
            }
            if ($stop) {
                break;
            }
        }
        $report['finishedAt'] = now('UTC')->toIso8601String();
        $report['attemptedRequests'] = $attempts;
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($output) {
            $handle = @fopen($output, 'x');
            if ($handle === false) {
                $this->error('Could not create output file.');

                return self::FAILURE;
            }
            $written = fwrite($handle, $json."\n");
            fclose($handle);
            if ($written === false || $written !== strlen($json."\n")) {
                $this->error('Could not write the complete report.');

                return self::FAILURE;
            }
            $this->info('Synthetic benchmark report written.');
        } else {
            $this->line($json);
        }

        return $stop || collect($report['runs'])->contains(fn ($row) => ! ($row['schemaValid'] ?? false)) ? self::FAILURE : self::SUCCESS;
    }
}
