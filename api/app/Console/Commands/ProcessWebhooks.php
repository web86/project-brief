<?php

namespace App\Console\Commands;

use App\Services\WebhookProcessor;
use Illuminate\Console\Command;
use Throwable;

class ProcessWebhooks extends Command
{
    protected $signature = 'integrations:webhooks {--limit=20} {--seconds=45}';

    protected $description = 'Deliver the transactional webhook outbox; quiet by default, statistics with --verbose';

    public function handle(WebhookProcessor $processor): int
    {
        $succeeded = 0;
        $errors = 0;
        $retries = 0;
        try {
            $count = $processor->process((int) $this->option('limit'), (int) $this->option('seconds'), function (array $outcome) use (&$succeeded, &$errors, &$retries): void {
                if ($outcome['code'] === null) {
                    $succeeded++;

                    return;
                }
                $errors++;
                $retry = $outcome['status'] === 'pending';
                $retries += (int) $retry;
                $this->output->getErrorStyle()->writeln(sprintf('%s webhook delivery=%s status=%s attempt=%d code=%s http=%s', now()->toIso8601String(), $outcome['delivery'], $retry ? 'retry' : 'failed', $outcome['attempt'], $outcome['code'], $outcome['httpStatus'] ?? '-'));
            });
        } catch (Throwable) {
            $this->output->getErrorStyle()->writeln(now()->toIso8601String().' webhook command=integrations:webhooks code=internal_error');

            return self::FAILURE;
        }
        if ($this->output->isVerbose()) {
            $this->line(sprintf('Processed deliveries: %d; succeeded: %d; errors: %d; retry: %d', $count, $succeeded, $errors, $retries));
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
