<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFeedback extends Model
{
    use HasFactory;

    protected $table = 'project_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'requested_at' => 'datetime', 'shown_at' => 'datetime', 'dismissed_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectClient(): BelongsTo
    {
        return $this->belongsTo(ProjectClient::class);
    }
}
