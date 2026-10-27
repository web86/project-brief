<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
        Passkeys::ignoreRoutes();
    }

    public function boot(): void
    {
        RateLimiter::for('security-passkeys', fn (Request $r) => [Limit::perMinute(10)->by('passkeys:'.$r->ip()), Limit::perMinute(10)->by('passkeys-session:'.$r->session()->getId())]);
        RateLimiter::for('security-challenge', function (Request $r): array {
            $pendingUser = DB::table('account_security_challenges')->where('id', $r->session()->get('security.pending'))->value('user_id');

            return [Limit::perMinute(5)->by('security:'.$r->ip()), Limit::perMinute(5)->by('security-account:'.($r->user()?->id ?? $pendingUser ?? $r->session()->getId()))];
        });
        RateLimiter::for('browser-pdf', fn (Request $request) => Limit::perMinute(config('browser_pdf.max_per_minute'))->by('browser-pdf:'.$request->session()->getId()));
        RateLimiter::for('admin-login', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()), Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('account-security', fn (Request $r) => [Limit::perMinute(5)->by('account:'.$r->user()?->id), Limit::perMinute(15)->by($r->ip())]);
        RateLimiter::for('account-recovery', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()), Limit::perMinute(3)->by('recovery:'.hash('sha256', mb_strtolower(trim((string) $r->input('email')))))]);
        RateLimiter::for('access', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('comments', fn (Request $r) => Limit::perMinute(30)->by($r->session()->getId()));
        foreach (['telegram-connect', 'telegram-test'] as $name) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute(5)->by($r->attributes->get('project_client') ? 'client:'.$r->attributes->get('project_client')->id : 'user:'.$r->user()?->id));
        }
        RateLimiter::for('telegram-webhook', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(15)->by($r->session()->getId()));
    }
}
