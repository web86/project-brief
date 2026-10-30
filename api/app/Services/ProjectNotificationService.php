<?php

namespace App\Services;

use App\Events\ProjectNotificationRequested;
use App\Mail\ProjectActivityMail;
use App\Models\Comment;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectNotificationSetting;
use App\Models\ProjectRequest;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProjectNotificationService
{
    public function __construct(private WebPushTransport $push, private TelegramTransport $telegram) {}

    public function settings(Project $project): ProjectNotificationSetting
    {
        return ProjectNotificationSetting::firstOrCreate(['project_id' => $project->id]);
    }

    public function primaryOwner(Project $project): ?User
    {
        return ProjectAccess::memberships($project)->where('role', 'owner')->orderBy('id')->first()?->user;
    }

    public function adminEmail(Project $project): ?string
    {
        $owner = $this->primaryOwner($project);

        return $owner ? $this->emailFor($project, $owner) : null;
    }

    private function emailFor(Project $project, User $recipient): ?string
    {
        if ($this->settings($project)->delivery_mode === 'personal' || $recipient->notification_email_resolved_at !== null) {
            return $recipient->notificationEmail();
        }
        $override = $this->settings($project)->notification_email;
        if ($override && $this->primaryOwner($project)?->id === $recipient->id) {
            $emailQuery = fn ($query) => $query->whereRaw('LOWER(email) = ?', [mb_strtolower($override)]);
            // A legacy external mailbox is allowed, but never route activity to a foreign account.
            if (! $emailQuery(User::query())->exists() || $emailQuery(ProjectAccess::recipients($project))->exists()) {
                return $override;
            }
        }

        return $recipient->email;
    }

    public function mailAvailable(): bool
    {
        return config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.host') && (bool) config('mail.from.address');
    }

    public function notify(ProjectNotificationRequested $event): void
    {
        try {
            $this->deliver($event);
        } catch (Throwable $exception) {
            Log::warning('Project notification dispatch failed', ['event' => $event->event, 'error_code' => 'dispatch_failed', 'exception_type' => get_class($exception)]);
        }
    }

    public function notifyRequest(ProjectRequest $row): void
    {
        try {
            $owners = $row->workspace->members()->active()->where('role', 'owner')->with('user')->get();
            foreach ($owners as $member) {
                $user = $member->user;
                $locale = SupportedLocales::resolve($user->preferred_locale);
                $payload = NotificationPayload::test($locale, rtrim(config('app.url'), '/').'/admin/requests?request='.$row->uuid);
                $heading = trans('notifications.project_request_heading', [], $locale);
                $payload = array_replace($payload, ['title' => $heading, 'heading' => $heading, 'subject' => '[ProjectBrief] '.$heading,
                    'body' => trans('notifications.project_request_message', [], $locale), 'explanation' => trans('notifications.project_request_message', [], $locale),
                    'project' => $row->workspace->name, 'open' => trans('notifications.open_request', [], $locale), 'event' => 'project_request.created',
                    'workspace_id' => $row->workspace_id, 'tag' => 'projectbrief-request-'.$row->uuid]);
                $preferences = $user->notificationPreferences();
                $key = 'request:'.$row->uuid.':'.$user->id;
                if ($preferences['email']) {
                    $this->attempt(null, 'project_request.created', 'email', 'admin', $user->id, $key.':email', fn () => $this->sendMail($user->notificationEmail(), $payload), $row->id);
                }
                if ($preferences['telegram']) {
                    $connection = TelegramLinking::query($user)->whereNotNull('chat_id')->first();
                    if ($connection) {
                        $this->attempt(null, 'project_request.created', 'telegram', 'admin', $user->id, $key.':telegram', fn () => $this->telegram->send($connection, TelegramTransport::message($payload)), $row->id);
                    }
                }
                if ($preferences['push']) {
                    foreach (PushSubscription::where('user_id', $user->id)->get() as $subscription) {
                        $this->attempt(null, 'project_request.created', 'push', 'admin', $user->id, $key.':push:'.$subscription->uuid,
                            fn () => $this->push->available() ? $this->push->send($subscription, NotificationPayload::push($payload)) : ['status' => 'skipped', 'error_code' => 'push_unconfigured'], $row->id);
                    }
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Project request notification dispatch failed', ['error_code' => 'dispatch_failed', 'exception_type' => get_class($exception)]);
        }
    }

    private function deliver(ProjectNotificationRequested $event): void
    {
        if (! in_array($event->event, NotificationEvents::CODES, true)) {
            return;
        }
        $team = $event->event === 'team.message_created';
        $comment = $event->projectCommentId ? Comment::withoutGlobalScope('public_channel')->where('team_only', $team)->find($event->projectCommentId) : null;
        $task = $event->taskId ? Task::with(['project', 'projectSection'])->find($event->taskId) : null;
        if ($event->projectCommentId && (! $comment || $comment->task_id !== null || $comment->author_type !== $event->actorType || ($team ? $event->actorType !== 'admin' : $event->event !== $event->actorType.'.project_comment_created'))) {
            return;
        }
        $project = $task?->project ?? ($comment ? Project::find($comment->project_id) : null);
        if (! $project || $project->status !== 'active') {
            return;
        }
        $settings = $this->settings($project);
        $rule = $settings->rules()[$event->event];
        if ($settings->delivery_mode === 'personal' && ! $rule['enabled']) {
            return;
        }
        $preferences = $settings->delivery_mode === 'personal' ? ['email' => true, 'push' => true, 'telegram' => true] : $settings->preferences()[$event->event];
        $toAdmin = NotificationEvents::toTeam($event->event);
        $recipients = $toAdmin ? ProjectAccess::recipients($project)->get() : $project->clients()->where('active', true)->whereHas('accessTokens', fn ($q) => $q->where('project_id', $project->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())))->get();
        $dedup = $comment ? 'comment:'.$comment->id : (string) $event->historyId;
        $actorEmail = $event->actorType === 'admin' ? User::find($event->actorId)?->email : ProjectClient::find($event->actorId)?->email;
        foreach ($recipients as $recipient) {
            $recipientKey = $recipient instanceof ProjectClient ? $recipient->uuid : $recipient->id;
            if ($settings->delivery_mode === 'personal' && $rule['recipients']['mode'] === 'selected' && ! in_array($recipientKey, $rule['recipients']['ids'], true)) {
                continue;
            }
            $type = $toAdmin ? 'admin' : 'client';
            if (($event->actorType === $type && $event->actorId === $recipient->id) || ($actorEmail && $recipient->email && strcasecmp($actorEmail, $recipient->email) === 0)) {
                continue;
            }
            $locale = $recipient->preferred_locale ?? ($toAdmin ? config('project_notifications.admin_locale') : 'en');
            $locale = SupportedLocales::resolve($locale);
            $payload = $comment ? ($team ? NotificationPayload::team($comment, $project, $locale) : NotificationPayload::discussion($event->event, $comment, $event->actorName, $locale, $toAdmin)) : NotificationPayload::make($event->event, $task, $event->actorName, $locale, $toAdmin, (string) $event->historyId);
            $personal = $recipient->notificationPreferences();
            $email = $team ? $recipient->notificationEmail() : ($toAdmin ? $this->emailFor($project, $recipient) : $recipient->email);
            // Avoid mail self-notification even if two personas share the same address.
            if ($preferences['email'] && $personal['email'] && filter_var($email, FILTER_VALIDATE_EMAIL) && (! $actorEmail || strcasecmp($actorEmail, $email) !== 0)) {
                $this->attempt($project, $event->event, 'email', $type, $recipient->id, $dedup.':email:'.$type.':'.$recipient->id,
                    fn () => $this->sendMail($email, $payload));
            }
            if ($preferences['telegram'] && $personal['telegram']) {
                $connection = TelegramLinking::query($recipient)->whereNotNull('chat_id')->first();
                if ($connection) {
                    $this->attempt($project, $event->event, 'telegram', $type, $recipient->id, $dedup.':telegram:'.$type.':'.$recipient->id,
                        fn () => $this->telegram->send($connection, TelegramTransport::message($payload)));
                }
            }
            if ($preferences['push'] && $personal['push']) {
                foreach (PushSubscription::where($toAdmin ? 'user_id' : 'project_client_id', $recipient->id)->get() as $subscription) {
                    $this->attempt($project, $event->event, 'push', $type, $recipient->id, $dedup.':push:'.$subscription->uuid,
                        fn () => $this->push->available() ? $this->push->send($subscription, NotificationPayload::push($payload)) : ['status' => 'skipped', 'error_code' => 'push_unconfigured']);
                }
            }
        }
    }

    private function attempt(?Project $project, string $event, string $channel, string $type, int $recipientId, string $key, callable $send, ?int $requestId = null): void
    {
        $key = hash('sha256', $key);
        $claimed = DB::table('notification_deliveries')->insertOrIgnore(['project_id' => $project?->id, 'project_request_id' => $requestId, 'dedup_key' => $key, 'event' => $event, 'channel' => $channel, 'recipient_type' => $type, 'recipient_id' => $recipientId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            $result = $send();
        } catch (Throwable $exception) {
            $result = ['status' => 'failed', 'error_code' => $channel.'_delivery_failed'];
            Log::warning('Project notification delivery failed', ['event' => $event, 'channel' => $channel, 'error_code' => $result['error_code'], 'exception_type' => get_class($exception)]);
        }
        NotificationDelivery::where('dedup_key', $key)->update($result);
    }

    public function sendMail(string $email, array $payload): array
    {
        if (! $this->mailAvailable()) {
            return ['status' => 'skipped', 'error_code' => 'smtp_unconfigured'];
        }
        $project = ! empty($payload['project_uuid']) ? Project::with('workspace')->where('uuid', $payload['project_uuid'])->first() : null;
        $workspace = $project?->workspace ?? (! empty($payload['workspace_id']) ? Workspace::find($payload['workspace_id']) : null);
        $payload['branding'] = $workspace ? app(WorkspaceBranding::class)->email($workspace) : null;
        Mail::to($email)->send(new ProjectActivityMail($payload));

        return ['status' => 'sent', 'error_code' => null];
    }

    public function personalTest(User $user, string $channel, ?string $endpoint): array
    {
        if (! ($user->notificationPreferences()[$channel] ?? false)) {
            return ['status' => 'skipped', 'error_code' => 'channel_disabled'];
        }
        $payload = NotificationPayload::test(SupportedLocales::resolve($user->preferred_locale), rtrim(config('app.url'), '/').'/admin/settings/notifications');
        try {
            if ($channel === 'email') {
                return $this->sendMail($user->notificationEmail(), $payload);
            }
            $subscription = $endpoint ? PushSubscription::where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $endpoint))->first() : null;
            if (! $subscription) {
                return ['status' => 'skipped', 'error_code' => 'device_not_subscribed'];
            }

            return $this->push->available() ? $this->push->send($subscription, NotificationPayload::push($payload)) : ['status' => 'skipped', 'error_code' => 'push_unconfigured'];
        } catch (Throwable) {
            Log::warning('Personal notification test failed', ['error_code' => $channel.'_delivery_failed']);

            return ['status' => 'failed', 'error_code' => $channel.'_delivery_failed'];
        }
    }

    public function testDelivery(Project $project, User $admin, string $channel, ?string $endpoint): array
    {
        if (! ($admin->notificationPreferences()[$channel] ?? false)) {
            return ['status' => 'skipped', 'error_code' => 'channel_disabled'];
        }
        $payload = NotificationPayload::test(SupportedLocales::resolve($admin->preferred_locale ?? config('project_notifications.admin_locale')), rtrim(config('app.url'), '/').'/admin/projects/'.$project->uuid);
        try {
            if ($channel === 'email') {
                $email = $this->emailFor($project, $admin);
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['status' => 'failed', 'error_code' => 'email_unavailable'];
                }

                return $this->sendMail($email, $payload);
            }
            $subscription = $endpoint ? PushSubscription::where('user_id', $admin->id)->where('endpoint_hash', hash('sha256', $endpoint))->first() : null;
            if (! $subscription) {
                return ['status' => 'skipped', 'error_code' => 'device_not_subscribed'];
            }

            return $this->push->available() ? $this->push->send($subscription, NotificationPayload::push($payload)) : ['status' => 'skipped', 'error_code' => 'push_unconfigured'];
        } catch (Throwable $exception) {
            Log::warning('Notification test failed', ['channel' => $channel, 'exception_type' => get_class($exception), 'error_code' => $channel.'_delivery_failed']);

            return ['status' => 'failed', 'error_code' => $channel.'_delivery_failed'];
        }
    }
}
