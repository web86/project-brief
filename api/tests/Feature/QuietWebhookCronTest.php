<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class QuietWebhookCronTest extends TestCase
{
    private string $database;

    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'quiet-webhook-test-');
        $this->environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->database, 'DB_URL' => '', 'LOG_CHANNEL' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
        $process = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), $this->environment);
        $process->mustRun();
    }

    protected function tearDown(): void
    {
        foreach ([$this->database, $this->database.'-wal', $this->database.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function runCommand(string $mode, array $options = []): Process
    {
        $process = new Process([PHP_BINARY, 'tests/quiet-webhook-process.php', $mode, ...$options], base_path(), $this->environment);
        $process->run();

        return $process;
    }

    private function silent(Process $process): void
    {
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_actual_process_is_absolutely_silent_for_idle_busy_lease_and_success(): void
    {
        $this->silent($this->runCommand('success'));
        $this->silent($this->runCommand('busy'));
        $this->silent($this->runCommand('success'));
    }

    public function test_successful_deliveries_are_silent_and_statistics_require_explicit_verbose(): void
    {
        $this->silent($this->runCommand('seed'));
        $this->silent($this->runCommand('success'));
        $stats = json_decode($this->runCommand('stats')->getOutput(), true);
        $this->assertSame(['succeeded', 'succeeded'], $stats['statuses']);
        $this->assertSame(2, $stats['attempts']);
        $verbose = $this->runCommand('success', ['--verbose']);
        $this->assertSame(0, $verbose->getExitCode());
        $this->assertStringContainsString('Processed deliveries: 0; succeeded: 0; errors: 0; retry: 0', $verbose->getOutput());
        $this->assertSame('', $verbose->getErrorOutput());
    }

    public function test_retry_emits_one_sanitized_error_then_backoff_and_successful_retry_are_silent(): void
    {
        $this->silent($this->runCommand('seed'));
        $failed = $this->runCommand('retry');
        $this->assertSame(1, $failed->getExitCode());
        $this->assertSame('', $failed->getOutput());
        $this->assertMatchesRegularExpression('/^\S+ webhook delivery=[a-f0-9-]+ status=retry attempt=1 code=remote_transient http=503\n$/', $failed->getErrorOutput());
        $this->assertStringNotContainsString('PRIVATE', $failed->getErrorOutput());
        $stats = json_decode($this->runCommand('stats')->getOutput(), true);
        $this->assertSame(['pending', 'succeeded'], $stats['statuses']);
        $this->assertSame(2, $stats['attempts']);
        $this->silent($this->runCommand('retry'));
        $this->silent($this->runCommand('due'));
        $this->silent($this->runCommand('success'));
        $stats = json_decode($this->runCommand('stats')->getOutput(), true);
        $this->assertSame(['succeeded', 'succeeded'], $stats['statuses']);
        $this->assertSame(3, $stats['attempts']);
    }

    public function test_rejected_delivery_does_not_block_another_endpoint_and_verbose_keeps_errors_on_stderr(): void
    {
        $this->silent($this->runCommand('seed'));
        $failed = $this->runCommand('mixed', ['-v']);
        $this->assertSame(1, $failed->getExitCode());
        $this->assertStringContainsString('Processed deliveries: 2; succeeded: 1; errors: 1; retry: 0', $failed->getOutput());
        $this->assertStringContainsString('status=failed attempt=1 code=remote_rejected http=400', $failed->getErrorOutput());
        $this->assertStringNotContainsString('PRIVATE', $failed->getErrorOutput());
        $this->assertSame(['failed', 'succeeded'], json_decode($this->runCommand('stats')->getOutput(), true)['statuses']);
    }

    public function test_transport_and_unsafe_destination_errors_never_log_exception_details_or_urls(): void
    {
        $this->silent($this->runCommand('seed'));
        $transport = $this->runCommand('transport');
        $this->assertSame(1, $transport->getExitCode());
        $this->assertSame('', $transport->getOutput());
        $this->assertSame(2, substr_count($transport->getErrorOutput(), 'code=transport_error'));
        $this->assertStringNotContainsString('PRIVATE', $transport->getErrorOutput());
        $this->silent($this->runCommand('due'));
        $unsafe = $this->runCommand('unsafe');
        $this->assertSame(1, $unsafe->getExitCode());
        $this->assertSame(2, substr_count($unsafe->getErrorOutput(), 'code=unsafe_destination'));
        $this->assertStringNotContainsString('PRIVATE', $unsafe->getErrorOutput());
        $this->assertSame(4, json_decode($this->runCommand('stats')->getOutput(), true)['attempts']);
    }

    public function test_internal_exception_is_sanitized_and_nonzero_even_with_debug_enabled(): void
    {
        $this->environment['APP_DEBUG'] = 'true';
        $failed = $this->runCommand('exception');
        $this->assertSame(1, $failed->getExitCode());
        $this->assertSame('', $failed->getOutput());
        $this->assertMatchesRegularExpression('/^\S+ webhook command=integrations:webhooks code=internal_error\n$/', $failed->getErrorOutput());
        $this->assertStringNotContainsString('PRIVATE', $failed->getErrorOutput());
        $fatal = $this->runCommand('fatal');
        $this->assertSame(1, $fatal->getExitCode());
        $this->assertSame('', $fatal->getOutput());
        $this->assertMatchesRegularExpression('/^\S+ webhook command=integrations:webhooks code=internal_error\n$/', $fatal->getErrorOutput());
    }

    public function test_history_database_failure_rolls_back_and_exits_nonzero_without_stack_trace(): void
    {
        $this->silent($this->runCommand('seed'));
        $failed = $this->runCommand('db-error');
        $this->assertSame(1, $failed->getExitCode());
        $this->assertSame('', $failed->getOutput());
        $this->assertStringContainsString('code=internal_error', $failed->getErrorOutput());
        $this->assertStringNotContainsString('SQL', $failed->getErrorOutput());
        $this->assertSame(['pending', 'pending'], json_decode($this->runCommand('stats')->getOutput(), true)['statuses']);
        $lockError = $this->runCommand('lock-error');
        $this->assertSame(1, $lockError->getExitCode());
        $this->assertStringContainsString('code=internal_error', $lockError->getErrorOutput());
    }
}
