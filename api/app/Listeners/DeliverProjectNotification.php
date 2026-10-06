<?php

namespace App\Listeners;

use App\Events\ProjectNotificationRequested;
use App\Services\ProjectNotificationService;

class DeliverProjectNotification
{
    public function handle(ProjectNotificationRequested $event): void
    {
        app()->terminating(fn () => app(ProjectNotificationService::class)->notify($event));
    }
}
