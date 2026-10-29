<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectClient extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['project_id', 'name', 'email', 'active', 'preferred_locale', 'notification_preferences', 'workspace_client_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'notification_preferences' => 'array'];
    }

    public function notificationPreferences(): array
    {
        return array_replace(['email' => true, 'push' => true, 'telegram' => true], $this->notification_preferences ?? []);
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(ProjectAccessToken::class)->orderBy('id');
    }
}
