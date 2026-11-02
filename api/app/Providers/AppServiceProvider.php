<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectFeedback;
use App\Models\ProjectMember;
use App\Models\ProjectRead;
use App\Models\ProjectSection;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Observers\LiveSyncObserver;
use App\Services\DemoEnvironment;
use App\Services\LiveSync;
use App\Services\StoreAccountPasskey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StorePasskey::class, StoreAccountPasskey::class);
        Fortify::ignoreRoutes();
        Passkeys::ignoreRoutes();
    }

    public function boot(): void
    {
        if (DemoEnvironment::enabled()) {
            DemoEnvironment::assertIsolated();
            config(['mail.default' => 'array', 'platform.metrics_enabled' => false, 'queue.default' => 'null']);
            Http::preventStrayRequests();
            Queue::before(fn () => throw new \RuntimeException('Jobs are disabled in demo.'));
            Event::listen(CommandStarting::class, function ($event): void {
                if (! in_array($event->command, ['demo:reset', 'demo:audit', 'migrate:fresh', 'migrate', 'migrate:status', 'db:wipe', 'db:seed', 'serve', 'list', 'help', 'route:list', 'test'], true)) {
                    throw new \RuntimeException('This command is unavailable in demo.');
                }
            });

        }

        foreach ([Project::class, Task::class, Comment::class, Attachment::class, ProjectSection::class, ProjectFeedback::class, ProjectRead::class, Workspace::class, WorkspaceMember::class, ProjectMember::class] as $model) {
            $model::observe(LiveSyncObserver::class);
        }
        $this->app->singleton(LiveSync::class);

        foreach (['platform' => 30, 'live-sync' => 30, 'invitation-resolve' => 20, 'invitation-accept' => 10, 'profile-update' => 30, 'notification-update' => 30, 'notification-verify' => 10, 'notification-test' => 5, 'integration-settings' => 30, 'integration-test' => 5, 'integration-retry' => 5, 'push-subscribe' => 30, 'project-notification-test' => 5] as $name => $maximum) {
            RateLimiter::for($name, function (Request $request) use ($maximum) {
                $client = $request->attributes->get('project_client');
                $actor = $client ? 'client:'.$client->id : ($request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip());

                return Limit::perMinute($maximum)->by($actor);
            });
        }
        RateLimiter::for('document-export', function (Request $request): array {
            $project = $request->route('project');
            abort_unless($project instanceof Project, 404);
            $client = $request->attributes->get('project_client');

            return [Limit::perMinute(5)->by($client ? 'client:'.$client->id : 'user:'.$request->user()->id), Limit::perMinute(10)->by('workspace:'.$project->workspace_id)];
        });
        RateLimiter::for('security-passkeys', fn (Request $r) => [Limit::perMinute(10)->by('passkeys:'.$r->ip()), Limit::perMinute(10)->by('passkeys-session:'.$r->session()->getId())]);
        RateLimiter::for('security-challenge', function (Request $r): array {
            $pendingUser = DB::table('account_security_challenges')->where('id', $r->session()->get('security.pending'))->value('user_id');

            return [Limit::perMinute(5)->by('security:'.$r->ip()), Limit::perMinute(5)->by('security-account:'.($r->user()?->id ?? $pendingUser ?? $r->session()->getId()))];
        });
        RateLimiter::for('browser-pdf', fn (Request $request) => Limit::perMinute(config('browser_pdf.max_per_minute'))->by('browser-pdf:'.$request->session()->getId()));
        RateLimiter::for('admin-login', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()), Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('account-security', fn (Request $r) => [Limit::perMinute(5)->by('account:'.$r->user()?->id), Limit::perMinute(15)->by($r->ip())]);
        RateLimiter::for('account-reset-inspect', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('account-recovery', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()), Limit::perMinute(3)->by('recovery:'.hash('sha256', mb_strtolower(trim((string) $r->input('email')))))]);
        RateLimiter::for('client-project-login', fn (Request $r) => [
            Limit::perMinute(5)->by('client-login-ip:'.$r->ip()),
            Limit::perMinutes(15, 3)->by('client-login-mailbox:'.hash('sha256', $r->route('uuid').'|'.mb_strtolower(trim(is_string($r->input('email')) ? $r->input('email') : '')))),
        ]);
        RateLimiter::for('client-sso', fn (Request $r) => Limit::perMinute(60)->by('client-sso:'.$r->ip()));
        RateLimiter::for('access', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('comments', fn (Request $r) => Limit::perMinute(30)->by($r->session()->getId()));
        foreach (['telegram-connect', 'telegram-test'] as $name) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute(5)->by($r->attributes->get('project_client') ? 'client:'.$r->attributes->get('project_client')->id : 'user:'.$r->user()?->id));
        }
        RateLimiter::for('telegram-webhook', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(15)->by($r->session()->getId()));
    }
}
