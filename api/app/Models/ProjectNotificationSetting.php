<?php

namespace App\Models;

use App\Services\NotificationEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectNotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'notification_email', 'events'];

    protected function casts(): array
    {
        return ['events' => 'array'];
    }

    public function preferences(): array
    {
        return array_replace_recursive(NotificationEvents::defaults(), $this->events ?? []);
    }
}
