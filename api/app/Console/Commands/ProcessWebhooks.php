<?php

namespace App\Console\Commands;

use App\Services\WebhookProcessor;
use Illuminate\Console\Command;

class ProcessWebhooks extends Command
{
    protected $signature = 'integrations:webhooks {--limit=20} {--seconds=45}';

    protected $description = 'Deliver the transactional webhook outbox with a database lease and bounded retries';

    public function handle(WebhookProcessor $processor): int
    {
        $count = $processor->process((int) $this->option('limit'), (int) $this->option('seconds'));
        $this->info('Processed deliveries: '.$count);

        return self::SUCCESS;
    }
}
