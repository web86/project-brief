<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectFeedback;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProjectFeedbacks
{
    public function eligible(Project $project): bool
    {
        if ($project->trashed() || $project->status !== 'active') {
            return false;
        }
        if (isset($project->tasks_count, $project->done_count)) {
            return $project->tasks_count > 0 && $project->tasks_count === $project->done_count;
        }

        return $project->tasks()->exists() && ! $project->tasks()->where('status', '!=', 'done')->exists();
    }

    public function clientState(Project $project, ProjectClient $client): array
    {
        $feedback = ProjectFeedback::where('project_id', $project->id)->where('project_client_id', $client->id)->first();
        $submitted = ProjectFeedback::where('project_id', $project->id)->whereNotNull('submitted_at')->exists();

        return ['eligible' => $this->eligible($project), 'requested' => (bool) $feedback?->requested_at,
            'shown' => (bool) $feedback?->shown_at, 'dismissed' => (bool) $feedback?->dismissed_at,
            'submitted' => $submitted];
    }

    /** An incomplete cycle invalidates only the previous prompt, never a submitted review. */
    public function invalidatePrompt(Task $task): void
    {
        if (! Schema::hasTable('project_feedback')) {
            return;
        }
        if ((! $task->trashed() && $task->status !== 'done') || ! Task::where('project_id', $task->project_id)->exists()) {
            ProjectFeedback::where('project_id', $task->project_id)->whereNull('submitted_at')
                ->where(fn ($q) => $q->whereNotNull('shown_at')->orWhereNotNull('dismissed_at'))
                ->update(['shown_at' => null, 'dismissed_at' => null]);
        }
    }

    public function entry(Project $project, ProjectClient $client): ProjectFeedback
    {
        abort_unless($client->active && $client->project_id === $project->id, 404);

        return ProjectFeedback::firstOrCreate(['project_id' => $project->id, 'project_client_id' => $client->id], ['workspace_id' => $project->workspace_id]);
    }

    /** Called in the feedback transaction, so mail intent survives process failures. */
    public function recordMail(ProjectFeedback $feedback): void
    {
        $owner = app(ProjectNotificationService::class)->primaryOwner($feedback->project);
        if (! $owner) {
            return;
        }
        $delivery = NotificationDelivery::firstOrCreate(['dedup_key' => hash('sha256', 'feedback:'.$feedback->id.':owner')],
            ['project_id' => $feedback->project_id, 'event' => 'client.feedback_submitted', 'channel' => 'email', 'recipient_type' => 'admin', 'recipient_id' => $owner->id, 'status' => 'pending']);
        $feedback->forceFill(['notification_delivery_id' => $delivery->id])->save();
    }

    /** Serialize attempts on the durable delivery row; HTTP retries never enqueue another message. */
    public function deliver(int $feedbackId): bool
    {
        return DB::transaction(function () use ($feedbackId): bool {
            $feedback = ProjectFeedback::with(['project', 'projectClient'])->find($feedbackId);
            if (! $feedback?->submitted_at || ! $feedback->notification_delivery_id) {
                return true;
            }
            $delivery = NotificationDelivery::whereKey($feedback->notification_delivery_id)->lockForUpdate()->first();
            if (! $delivery || $delivery->status === 'sent' || $delivery->status === 'skipped') {
                return true;
            }
            $project = $feedback->project;
            $service = app(ProjectNotificationService::class);
            $owner = $project ? $service->primaryOwner($project) : null;
            if (! $owner || $owner->id !== $delivery->recipient_id) {
                $delivery->update(['status' => 'skipped', 'error_code' => 'recipient_unavailable']);

                return true;
            }
            $locale = SupportedLocales::resolve($owner->preferred_locale);
            $payload = NotificationPayload::test($locale, rtrim(config('app.url'), '/').'/admin/projects/'.$project->uuid.'/brief');
            $heading = trans('feedback.mail_heading', [], $locale);
            $payload = array_replace($payload, ['heading' => $heading, 'subject' => '[ProjectBrief] '.$heading.' — '.NotificationPayload::excerpt($project->title, 80),
                'project' => $project->title, 'project_uuid' => $project->uuid, 'message' => $feedback->text, 'message_label' => trans('feedback.text', [], $locale),
                'explanation' => trans('feedback.mail_message', ['client' => $feedback->projectClient->name, 'rating' => $feedback->rating, 'date' => $feedback->submitted_at->toIso8601String()], $locale), 'next' => '']);
            try {
                $result = $service->sendMail($owner->notificationEmail(), $payload);
                if ($result['status'] === 'skipped') {
                    $result['status'] = 'failed';
                }
            } catch (Throwable) {
                $result = ['status' => 'failed', 'error_code' => 'email_delivery_failed'];
            }
            $delivery->update($result);
            if ($result['status'] !== 'sent') {
                Log::warning('Feedback email delivery failed', ['delivery_id' => $delivery->id, 'error_code' => $result['error_code']]);
            }

            return $result['status'] === 'sent';
        }, 3);
    }
}
