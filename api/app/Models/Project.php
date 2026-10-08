<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids, \Illuminate\Database\Eloquent\Factories\HasFactory;

    /** Legacy client_name, client_email and sections are retained for migration compatibility only. */
    protected $fillable = ['title', 'website_url', 'client_name', 'client_email', 'currency', 'status', 'sections', 'workspace_id'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'workspace_id' => 'integer'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::created(fn (self $project) => ProjectNotificationSetting::create(['project_id' => $project->id]));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function briefSections(): HasMany
    {
        return $this->hasMany(ProjectSection::class)->orderBy('position')->orderBy('id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(ProjectClient::class)->orderBy('id');
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(ProjectAccessToken::class)->orderBy('id');
    }
}
