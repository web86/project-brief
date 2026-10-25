<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends Model
{
    protected $fillable = ['workspace_id', 'project_id', 'workspace_member_id', 'permissions'];

    protected function casts(): array
    {
        return ['workspace_id' => 'integer', 'project_id' => 'integer', 'workspace_member_id' => 'integer', 'permissions' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workspaceMember(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMember::class);
    }
}
