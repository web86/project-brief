<?php

namespace App\Models;

use App\Services\NotificationEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectNotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'notification_email', 'events', 'delivery_mode', 'event_rules'];

    protected function casts(): array
    {
        return ['events' => 'array', 'event_rules' => 'array'];
    }

    public function rules(): array
    {
        $rules = [];
        foreach ($this->preferences() as $event => $channels) {
            $rules[$event] = ['enabled' => (bool) array_filter($channels), 'recipients' => ['mode' => 'all', 'ids' => []]];
        }

        return array_replace($rules, $this->event_rules ?? []);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function preferences(): array
    {
        return array_replace_recursive(NotificationEvents::defaults(), $this->events ?? []);
    }
}
