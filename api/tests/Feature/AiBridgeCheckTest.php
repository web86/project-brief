<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiBridgeCheckTest extends TestCase
{
    private const URL = 'https://projectbrief-ai-bridge.web86.deno.net/ai';

    private const SECRET = 'fake-bridge-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.providers.groq_bridge.url' => self::URL, 'ai.providers.groq_bridge.key' => self::SECRET, 'ai.providers.groq_bridge.model' => 'openai/gpt-oss-120b']);
    }

    private static function suggestion(): array
    {
        return ['title' => 'Увеличить фотографии', 'description' => 'Увеличить фото товаров и добавить листание на телефоне.', 'expectedResult' => 'Фото крупнее, их можно листать на телефоне.', 'questions' => [], 'suggestedIdeas' => []];
    }

    private static function success(): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(self::suggestion())]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150]];
    }

    public function test_plan_uses_neither_network_nor_database(): void
    {
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(self::success())]);

        $this->artisan('ai:bridge-check')->expectsOutputToContain('Plan only: one synthetic')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_live_check_makes_one_synthetic_request_through_real_service_validator_without_database(): void
    {
        DB::shouldReceive('connection')->never();
        Log::spy();
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(self::success())]);
        $output = sys_get_temp_dir().'/bridge-check-'.Str::uuid().'.json';

        try {
            $this->artisan('ai:bridge-check', ['--live' => true, '--output' => $output])->assertSuccessful();
            $report = json_decode(file_get_contents($output), true);
            $this->assertSame(200, $report['httpStatus']);
            $this->assertTrue($report['syntheticOnly']);
            $this->assertSame(1, $report['requests']);
            $this->assertTrue($report['jsonValid']);
            $this->assertTrue($report['schemaValid']);
            $this->assertSame(self::suggestion(), $report['suggestion']);
            $this->assertSame(150, $report['usage']['total_tokens']);
            $this->assertStringNotContainsString(self::SECRET, file_get_contents($output));
        } finally {
            @unlink($output);
        }
        Http::assertSent(function (Request $request): bool {
            $fields = json_decode($request['messages'][1]['content'], true);
            $this->assertSame('Фото товаров на телефоне', $fields['title']);
            $this->assertSame(['title', 'description', 'expectedResult', 'section', 'location'], array_keys($fields));
            $this->assertTrue($request['response_format']['json_schema']['strict']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::SECRET));

            return $request->url() === self::URL;
        });
        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    public static function failures(): array
    {
        return [
            'wrong secret' => [401, ['error' => 'unauthorized'], null, 'ai_unavailable'],
            'raw forbidden' => [403, ['error' => 'forbidden'], null, 'ai_unavailable'],
            'provider forbidden' => [502, ['error' => 'groq_request_failed', 'providerStatus' => 403], 403, 'ai_unavailable'],
            'rate limit' => [429, ['error' => 'groq_request_failed', 'providerStatus' => 429], 429, 'ai_provider_limit'],
            'bad gateway' => [502, ['error' => 'invalid_upstream_reply'], null, 'ai_unavailable'],
            'not configured' => [503, ['error' => 'not_configured'], null, 'ai_unavailable'],
            'bridge timeout' => [504, ['error' => 'upstream_timeout_or_network_error'], null, 'ai_timeout'],
            'invalid JSON' => [200, 'not JSON', null, 'ai_invalid_response'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failure_is_reported_without_retry_fallback_or_false_success(int $status, array|string $body, ?int $providerStatus, string $error): void
    {
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::sequence()->push($body, $status)->push(self::success())]);
        $output = sys_get_temp_dir().'/bridge-failure-'.Str::uuid().'.json';

        try {
            $this->artisan('ai:bridge-check', ['--live' => true, '--output' => $output])->assertFailed();
            $report = json_decode(file_get_contents($output), true);
            $this->assertSame($status, $report['httpStatus']);
            $this->assertSame($providerStatus, $report['providerStatus'] ?? null);
            $this->assertSame($error, $report['error']);
            $this->assertNull($report['suggestion']);
        } finally {
            @unlink($output);
        }
        Http::assertSentCount(1);
    }

    public function test_connection_timeout_reports_no_http_response_and_does_not_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::failedConnection('sensitive connection details')]);

        $this->artisan('ai:bridge-check', ['--live' => true])->expectsOutputToContain('schema not accepted')->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_missing_secret_fails_without_any_outgoing_request(): void
    {
        config(['ai.providers.groq_bridge.key' => '']);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(self::success())]);

        $this->artisan('ai:bridge-check', ['--live' => true])->expectsOutput('Bridge check did not send a request: ai_disabled')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_existing_report_is_not_overwritten_and_no_request_is_sent(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'bridge-existing-');
        file_put_contents($output, 'keep');
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(self::success())]);

        try {
            $this->artisan('ai:bridge-check', ['--live' => true, '--output' => $output])->assertExitCode(2);
            $this->assertSame('keep', file_get_contents($output));
        } finally {
            unlink($output);
        }
        Http::assertNothingSent();
    }
}
