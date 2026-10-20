<?php

namespace App\Console\Commands;

use App\Services\TelegramTransport;
use App\Services\TelegramWebhook;
use Illuminate\Console\Command;

class CheckTelegram extends Command
{
    protected $signature = 'telegram:check {--live : Send one getMe request using the configured transport}';

    protected $description = 'Diagnose Telegram connectivity without sending private messages';

    public function handle(TelegramTransport $transport): int
    {
        $this->line('Transport: '.config('telegram.transport'));
        $this->line($transport->available() ? 'Transport configured.' : 'Transport not configured.');
        $this->line('Webhook transport: '.config('telegram.webhook_transport'));
        $this->line(TelegramWebhook::configured() ? 'Webhook configured.' : 'Webhook not configured.');
        $this->line(function_exists('fastcgi_finish_request') ? 'Response flush: FastCGI.' : 'Response flush: fallback (verify callback latency on production).');
        if (! $this->option('live')) {
            $this->line('No network request. Use --live on Jino to diagnose connectivity.');

            return $transport->available() && TelegramWebhook::configured() ? self::SUCCESS : self::FAILURE;
        }
        $result = $transport->diagnose();
        $this->line($result['error_code'] ?? 'Telegram getMe: OK');

        return $result['status'] === 'sent' ? self::SUCCESS : self::FAILURE;
    }
}
