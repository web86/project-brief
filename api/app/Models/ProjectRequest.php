<?php

namespace App\Models;

use Database\Factories\ProjectRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRequest extends Model
{
    /** @use HasFactory<ProjectRequestFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'send_invitation' => 'boolean', 'workspace_id' => 'integer', 'result' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
