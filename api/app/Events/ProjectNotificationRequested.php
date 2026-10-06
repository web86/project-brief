<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class ProjectNotificationRequested implements ShouldDispatchAfterCommit
{
    public function __construct(public int $historyId, public string $event, public int $taskId, public string $actorType, public ?int $actorId, public string $actorName) {}
}
