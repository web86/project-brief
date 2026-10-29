<?php

namespace App\Services;

use App\Mail\AccountActionMail;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AccountMail
{
    public function localized(string $email, ?string $locale, string $event, ?string $url = null, array $params = [], ?Workspace $workspace = null, ?string $actionKey = null): bool
    {
        $locale = SupportedLocales::resolve($locale);

        return $this->send($email, trans('account.'.$event.'_heading', [], $locale), trans('account.'.$event.'_message', $params, $locale), $url, $locale, $workspace ? app(WorkspaceBranding::class)->email($workspace) : null, $actionKey ? trans('account.'.$actionKey, [], $locale) : null);
    }

    public function send(string $email, string $heading, string $message, ?string $url = null, string $locale = 'en', ?array $branding = null, ?string $actionLabel = null): bool
    {
        // Never send secrets to Laravel's log mail transport.
        if (! app(ProjectNotificationService::class)->mailAvailable()) {
            return false;
        }
        try {
            Mail::to($email)->send(new AccountActionMail($heading, $message, $url, $locale, $branding, $actionLabel));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Account email delivery failed', ['error_code' => 'account_mail_failed', 'exception_type' => get_class($exception)]);

            return false;
        }
    }
}
