<?php

namespace App\Services;

use App\Events\ProjectNotificationRequested;
use App\Mail\ProjectActivityMail;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectNotificationSetting;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProjectNotificationService
{
    public function __construct(private WebPushTransport $push) {}

    public function settings(Project $project): ProjectNotificationSetting
    {
        return ProjectNotificationSetting::firstOrCreate(['project_id' => $project->id]);
    }

    public function admin(Project $project): ?User
    {
        return User::where('role', 'admin')->orderBy('id')->first();
    }

    public function adminEmail(Project $project): ?string
    {
        return $this->settings($project)->notification_email ?: $this->admin($project)?->email;
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

    private function deliver(ProjectNotificationRequested $event): void
    {
        if (! in_array($event->event, NotificationEvents::CODES, true)) {
            return;
        }
        $task = Task::with(['project', 'projectSection'])->find($event->taskId);
        if (! $task || $task->project->status !== 'active') {
            return;
        }
        $settings = $this->settings($task->project);
        $preferences = $settings->preferences()[$event->event];
        $toAdmin = str_starts_with($event->event, 'client.');
        $recipients = $toAdmin ? collect([$this->admin($task->project)])->filter() : $task->project->clients()->where('active', true)->whereHas('accessTokens', fn ($q) => $q->where('project_id', $task->project_id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())))->get();
        $actorEmail = $event->actorType === 'admin' ? User::find($event->actorId)?->email : ProjectClient::find($event->actorId)?->email;
        foreach ($recipients as $recipient) {
            $type = $toAdmin ? 'admin' : 'client';
            if (($event->actorType === $type && $event->actorId === $recipient->id) || ($actorEmail && $recipient->email && strcasecmp($actorEmail, $recipient->email) === 0)) {
                continue;
            }
            $locale = $toAdmin ? config('project_notifications.admin_locale') : ($recipient->preferred_locale ?? 'en');
            $locale = in_array($locale, ['ru', 'en'], true) ? $locale : ($toAdmin ? 'ru' : 'en');
            $payload = NotificationPayload::make($event->event, $task, $event->actorName, $locale, $toAdmin, (string) $event->historyId);
            $email = $toAdmin ? ($settings->notification_email ?: $recipient->email) : $recipient->email;
            // Avoid mail self-notification even if two personas share the same address.
            if ($preferences['email'] && filter_var($email, FILTER_VALIDATE_EMAIL) && (! $actorEmail || strcasecmp($actorEmail, $email) !== 0)) {
                $this->attempt($task->project, $event->event, 'email', $type, $recipient->id, $event->historyId.':email:'.$type.':'.$recipient->id,
                    fn () => $this->sendMail($email, $payload));
            }
            if ($preferences['push']) {
                foreach (PushSubscription::where($toAdmin ? 'user_id' : 'project_client_id', $recipient->id)->get() as $subscription) {
                    $this->attempt($task->project, $event->event, 'push', $type, $recipient->id, $event->historyId.':push:'.$subscription->uuid,
                        fn () => $this->push->available() ? $this->push->send($subscription, NotificationPayload::push($payload)) : ['status' => 'skipped', 'error_code' => 'push_unconfigured']);
                }
            }
        }
    }

    private function attempt(Project $project, string $event, string $channel, string $type, int $recipientId, string $key, callable $send): void
    {
        $key = hash('sha256', $key);
        $claimed = DB::table('notification_deliveries')->insertOrIgnore(['project_id' => $project->id, 'dedup_key' => $key, 'event' => $event, 'channel' => $channel, 'recipient_type' => $type, 'recipient_id' => $recipientId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
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
        Mail::to($email)->send(new ProjectActivityMail($payload));

        return ['status' => 'sent', 'error_code' => null];
    }

    public function testDelivery(Project $project, User $admin, string $channel, ?string $endpoint): array
    {
        $payload = NotificationPayload::test(config('project_notifications.admin_locale') === 'en' ? 'en' : 'ru', rtrim(config('app.url'), '/').'/admin/projects/'.$project->uuid);
        try {
            if ($channel === 'email') {
                $email = $this->adminEmail($project);
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
