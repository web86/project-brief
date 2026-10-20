<?php

namespace App\Console\Commands;

use App\Services\TelegramTransport;
use Illuminate\Console\Command;

class CheckTelegram extends Command
{
    protected $signature = 'telegram:check {--live : Send one getMe request using the configured transport}';

    protected $description = 'Diagnose Telegram connectivity without sending private messages';

    public function handle(TelegramTransport $transport): int
    {
        $this->line('Transport: '.config('telegram.transport'));
        $this->line($transport->available() ? 'Transport configured.' : 'Transport not configured.');
        if (! $this->option('live')) {
            $this->line('No network request. Use --live on Jino to diagnose connectivity.');

            return self::SUCCESS;
        }
        $result = $transport->diagnose();
        $this->line($result['error_code'] ?? 'Telegram getMe: OK');

        return $result['status'] === 'sent' ? self::SUCCESS : self::FAILURE;
    }
}
