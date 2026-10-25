<?php

namespace App\Console\Commands;

use App\Services\AccountMail;
use Illuminate\Console\Command;

class CheckAccountMail extends Command
{
    protected $signature = 'account:mail-check {--live : Send two synthetic emails using configured SMTP} {--to= : Explicit test recipient}';

    protected $description = 'Check invitation and recovery mail rendering and SMTP without reading accounts or issuing working access links';

    public function handle(AccountMail $mail): int
    {
        if (! $this->option('live')) {
            $this->info('Plan only: two synthetic emails, no database changes. Use --live --to=your-test-mailbox.');

            return self::SUCCESS;
        }
        $recipient = $this->option('to');
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('An explicit valid --to test mailbox is required.');

            return self::INVALID;
        }
        foreach (['Приглашение в команду / Team invitation', 'Восстановление пароля / Password recovery'] as $subject) {
            if (! $mail->send($recipient, $subject, 'Milestone 4.8: тестовое письмо, доступа не предоставляет. / Test email, does not grant access.', rtrim(config('app.url'), '/').'/admin/login')) {
                $this->error('SMTP delivery failed or SMTP is not configured. No retry.');

                return self::FAILURE;
            }
        }
        $this->info('SMTP accepted two synthetic emails. Check receipt, spam folder, RU/EN text and links manually.');

        return self::SUCCESS;
    }
}
