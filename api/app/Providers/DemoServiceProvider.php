<?php

namespace App\Providers;

use App\Services\DemoEnvironment;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/** Optional local module; this provider and its runtime are omitted from production packages. */
class DemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (DemoEnvironment::enabled()) {
            DemoEnvironment::assertIsolated();
            config(['mail.default' => 'array', 'platform.metrics_enabled' => false, 'queue.default' => 'null']);
            Http::preventStrayRequests();
            Queue::before(fn () => throw new \RuntimeException('Jobs are disabled in demo.'));
            Event::listen(CommandStarting::class, function ($event): void {
                if (! in_array($event->command, ['demo:reset', 'demo:audit', 'demo:rebrand', 'demo:export-static', 'migrate:fresh', 'migrate', 'migrate:status', 'db:wipe', 'db:seed', 'serve', 'list', 'help', 'route:list', 'test'], true)) {
                    throw new \RuntimeException('This command is unavailable in demo.');
                }
            });

        }

    }
}
