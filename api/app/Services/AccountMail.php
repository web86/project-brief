<?php

namespace App\Services;

use App\Mail\AccountActionMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AccountMail
{
    public function send(string $email, string $heading, string $message, ?string $url = null): bool
    {
        // Never send secrets to Laravel's log mail transport.
        if (! app(ProjectNotificationService::class)->mailAvailable()) {
            return false;
        }
        try {
            Mail::to($email)->send(new AccountActionMail($heading, $message, $url));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Account email delivery failed', ['error_code' => 'account_mail_failed', 'exception_type' => get_class($exception)]);

            return false;
        }
    }
}
