<?php

namespace Tests\Feature;

use App\Console\Commands\BenchmarkAi;
use App\Services\AiTaskAssistant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiBenchmarkTest extends TestCase
{
    private const URL = 'https://api.zveno.ai/v1/chat/completions';

    private const CATALOG = 'https://api.zveno.ai/v1/models';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.providers.zveno.key' => 'benchmark-test-secret']);
    }

    private function catalog(): array
    {
        return ['data' => array_map(fn ($id) => ['id' => $id, 'pricing' => ['prompt' => '0', 'completion' => '0'], 'supported_parameters' => ['response_format']], AiTaskAssistant::BENCHMARK_MODELS)];
    }

    private function completion(): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['title' => 'Название', 'description' => 'Описание', 'expectedResult' => '', 'questions' => [], 'suggestedIdeas' => []])]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120]];
    }

    public function test_plan_requires_live_opt_in_and_reads_neither_network_nor_database(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog())]);
        DB::shouldReceive('connection')->never();

        $this->artisan('ai:benchmark')->expectsOutputToContain('Plan only: 3 models × 3 synthetic scenarios')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_nine_live_requests_use_only_synthetic_data_and_same_strict_parameters_without_changing_config(): void
    {
        $this->freezeTime();
        Sleep::fake();
        Log::spy();
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog()), self::URL => Http::response($this->completion())]);
        $configuration = config('ai');
        $output = sys_get_temp_dir().'/ai-benchmark-'.Str::uuid().'.json';

        try {
            $this->artisan('ai:benchmark', ['--live' => true, '--output' => $output])->assertSuccessful();
            $report = json_decode(file_get_contents($output), true);
            $this->assertTrue($report['syntheticOnly']);
            $this->assertCount(9, $report['runs']);
            foreach ($report['runs'] as $row) {
                $this->assertSame(200, $row['httpStatus']);
                $this->assertTrue($row['jsonValid']);
                $this->assertTrue($row['schemaValid']);
                $this->assertSame(120, $row['usage']['total_tokens']);
                $this->assertNull($row['error']);
                $this->assertSame(BenchmarkAi::SCENARIOS[$row['scenario']], $row['input']);
            }
            $this->assertStringNotContainsString('benchmark-test-secret', file_get_contents($output));
            $this->assertStringContainsString('Not scored automatically', $report['expertReview']);
        } finally {
            @unlink($output);
        }
        Http::assertSentCount(10);
        $requests = Http::recorded(fn (Request $request) => $request->url() === self::URL);
        $common = null;
        foreach ($requests as [$request]) {
            $this->assertContains($request['model'], AiTaskAssistant::BENCHMARK_MODELS);
            $this->assertTrue($request['response_format']['json_schema']['strict']);
            $this->assertSame(6000, $request['max_tokens']);
            $this->assertSame(['effort' => 'low', 'exclude' => true], $request['reasoning']);
            $this->assertTrue($request['provider']['require_parameters']);
            $this->assertFalse($request['provider']['allow_fallbacks']);
            $this->assertSame(['prompt' => 0, 'completion' => 0], $request['provider']['max_price']);
            $source = json_decode($request['messages'][1]['content'], true);
            $this->assertContains($source['description'], BenchmarkAi::SCENARIOS);
            $this->assertSame(['title', 'description', 'expectedResult', 'section', 'location'], array_keys($source));
            $body = $request->data();
            unset($body['model'], $body['messages'][1]);
            $common ??= $body;
            $this->assertSame($common, $body);
        }
        Sleep::assertSleptTimes(8);
        $this->assertSame($configuration, config('ai'));
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    public static function invalidOptions(): array
    {
        return [
            'paid model' => [['--model' => ['nvidia/nemotron-3-super-120b-a12b']]],
            'unknown scenario' => [['--scenario' => ['customer-task']]],
            'unknown format' => [['--format' => 'xml']],
            'no pacing' => [['--delay' => '0']],
            'unbounded pacing' => [['--delay' => '999']],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function test_unapproved_models_and_scenarios_are_rejected_before_network(array $options): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog())]);

        $this->artisan('ai:benchmark', ['--live' => true, ...$options])->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_missing_key_fails_without_claiming_a_live_benchmark(): void
    {
        config(['ai.providers.zveno.key' => '']);
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog())]);

        $this->artisan('ai:benchmark', ['--live' => true])->expectsOutputToContain('No live requests were sent')->assertFailed();

        Http::assertNothingSent();
    }

    public static function stops(): array
    {
        return ['unauthorized' => [401], 'balance' => [402], 'forbidden' => [403], 'rate' => [429]];
    }

    #[DataProvider('stops')]
    public function test_account_and_rate_errors_stop_run_without_retries_or_fallback(int $status): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog()), self::URL => Http::response(['error' => 'PRIVATE-KEY'], $status)]);

        $this->artisan('ai:benchmark', ['--live' => true])->expectsOutputToContain('Stopping this run')->assertFailed();

        Http::assertSentCount(2);
        Sleep::assertNeverSlept();
    }

    public function test_absent_or_paid_catalog_routes_are_skipped_without_replacement(): void
    {
        $catalog = $this->catalog();
        $catalog['data'][0]['pricing']['prompt'] = '0.1';
        array_pop($catalog['data']);
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($catalog), self::URL => Http::response($this->completion())]);

        $this->artisan('ai:benchmark', ['--live' => true, '--scenario' => ['simple']])->assertFailed();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL && $request['model'] === AiTaskAssistant::BENCHMARK_MODELS[1]);
        Sleep::assertNeverSlept();
    }

    public function test_http_400_skips_other_scenarios_for_same_model_without_format_fallback(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog()), self::URL => Http::response(['error' => ['message' => 'no provider supporting response format']], 400)]);

        $this->artisan('ai:benchmark', ['--live' => true])->expectsOutputToContain('Skipping remaining scenarios')->assertFailed();

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(2);
        foreach (Http::recorded(fn (Request $request) => $request->url() === self::URL) as [$request]) {
            $this->assertSame('json_schema', $request['response_format']['type']);
            $this->assertTrue($request['response_format']['json_schema']['strict']);
        }
    }

    public function test_catalog_timeout_stops_before_any_generation(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::failedConnection('PRIVATE')]);

        $this->artisan('ai:benchmark', ['--live' => true])->expectsOutput('Catalog unavailable: ai_timeout')->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_invalid_catalog_stops_without_guessing_routes(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response('not JSON')]);

        $this->artisan('ai:benchmark', ['--live' => true])->expectsOutput('Catalog unavailable: ai_invalid_response')->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_report_never_overwrites_existing_file(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'ai-existing-');
        file_put_contents($output, 'keep');
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog())]);

        try {
            $this->artisan('ai:benchmark', ['--live' => true, '--output' => $output])->assertExitCode(2);
            $this->assertSame('keep', file_get_contents($output));
        } finally {
            unlink($output);
        }
        Http::assertNothingSent();
    }

    public function test_diagnostics_distinguish_valid_json_from_invalid_schema_and_truncation(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{}']]]])
            ->push(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{']]]])]);
        $assistant = app(AiTaskAssistant::class);

        $wrong = $assistant->benchmark(AiTaskAssistant::BENCHMARK_MODELS[0], ['description' => BenchmarkAi::SCENARIOS['simple']]);
        $truncated = $assistant->benchmark(AiTaskAssistant::BENCHMARK_MODELS[0], ['description' => BenchmarkAi::SCENARIOS['simple']]);

        $this->assertTrue($wrong['jsonValid']);
        $this->assertFalse($wrong['schemaValid']);
        $this->assertSame('ai_invalid_response', $wrong['error']);
        $this->assertFalse($truncated['jsonValid']);
        $this->assertSame('length', $truncated['finishReason']);
        $this->assertNull($truncated['suggestion']);
        Http::assertSentCount(2);
    }

    public static function diagnosticFormats(): array
    {
        return [['json_object'], ['text']];
    }

    #[DataProvider('diagnosticFormats')]
    public function test_weaker_formats_are_explicit_cli_diagnostics_and_do_not_change_production(string $format): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog()), self::URL => Http::response($this->completion())]);
        $before = config('ai');

        $this->artisan('ai:benchmark', ['--live' => true, '--format' => $format, '--model' => [AiTaskAssistant::BENCHMARK_MODELS[0]], '--scenario' => ['simple']])->assertSuccessful();

        Http::assertSent(function (Request $request) use ($format): bool {
            if ($request->url() !== self::URL) {
                return false;
            }
            if ($format === 'json_object') {
                $this->assertSame(['type' => 'json_object'], $request['response_format']);
            } else {
                $this->assertArrayNotHasKey('response_format', $request->data());
            }
            $this->assertStringContainsString('Diagnostic output only', $request['messages'][0]['content']);

            return true;
        });
        $this->assertSame($before, config('ai'));
    }

    public function test_synthetic_error_diagnostics_redact_key_and_web_errors_never_include_provider_text(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(['error' => ['message' => 'key benchmark-test-secret rejected']], 400)]);

        $result = app(AiTaskAssistant::class)->benchmark(AiTaskAssistant::BENCHMARK_MODELS[0], ['description' => BenchmarkAi::SCENARIOS['simple']]);

        $this->assertSame('key [REDACTED] rejected', $result['providerError']);
        $this->assertStringNotContainsString('benchmark-test-secret', json_encode($result));
        Http::assertSentCount(1);
    }

    public function test_prompt_json_benchmark_uses_same_minimal_validated_mode_as_form(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::CATALOG => Http::response($this->catalog()), self::URL => Http::response($this->completion())]);
        $configuration = config('ai');

        $this->artisan('ai:benchmark', ['--live' => true, '--format' => 'prompt_json', '--model' => [AiTaskAssistant::BENCHMARK_MODELS[0]], '--scenario' => ['simple']])->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::URL) {
                return false;
            }
            $this->assertSame(['model', 'messages', 'max_tokens', 'provider'], array_keys($request->data()));
            $this->assertSame(['allow_fallbacks' => false, 'max_price' => ['prompt' => 0, 'completion' => 0]], $request['provider']);
            $this->assertStringContainsString('Return exactly one JSON object conforming to this JSON Schema', $request['messages'][0]['content']);
            $this->assertArrayNotHasKey('response_format', $request->data());
            $this->assertArrayNotHasKey('reasoning', $request->data());

            return true;
        });
        Http::assertSentCount(2);
        $this->assertSame($configuration, config('ai'));
    }
}
