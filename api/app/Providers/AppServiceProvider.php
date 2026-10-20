<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('admin-login', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()), Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('access', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('comments', fn (Request $r) => Limit::perMinute(30)->by($r->session()->getId()));
        foreach (['telegram-connect', 'telegram-test'] as $name) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute(5)->by($r->attributes->get('project_client') ? 'client:'.$r->attributes->get('project_client')->id : 'user:'.$r->user()?->id));
        }
        RateLimiter::for('telegram-webhook', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(15)->by($r->session()->getId()));
    }
}
