<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Models\ProjectFeedback;
use App\Services\ProjectFeedbacks;
use Illuminate\Console\Command;

class DeliverFeedbackMail extends Command
{
    protected $signature = 'projects:feedback-mail {--limit=20}';

    protected $description = 'Retry durable feedback email deliveries; quiet on success';

    public function handle(ProjectFeedbacks $feedbacks): int
    {
        $failed = false;
        $ids = NotificationDelivery::where('event', 'client.feedback_submitted')->whereIn('status', ['pending', 'failed'])->where('updated_at', '<=', now()->subMinutes(5))->orderBy('updated_at')->orderBy('id')->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        foreach (ProjectFeedback::whereIn('notification_delivery_id', $ids)->pluck('id') as $id) {
            if (! $feedbacks->deliver($id)) {
                $failed = true;
                $this->error('Feedback email delivery failed; check notification_deliveries.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
