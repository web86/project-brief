<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasUuids, \Illuminate\Database\Eloquent\Factories\HasFactory;

    // section is deprecated, retained only for legacy backfill/fallback.
    protected $fillable = ['project_id', 'project_section_id', 'position', 'title', 'location', 'section', 'description', 'expected_result', 'priority', 'status', 'client_approved', 'client_approved_at', 'estimate_hours', 'price', 'developer_notes'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'client_approved' => 'boolean', 'client_approved_at' => 'datetime', 'estimate_hours' => 'float', 'price' => 'float'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isClientEditable(): bool
    {
        return ! $this->client_approved && in_array($this->status, ['new', 'clarification']);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectSection(): BelongsTo
    {
        return $this->belongsTo(ProjectSection::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TaskHistory::class)->orderBy('id');
    }
}
