<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceMember extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'user_id', 'role', 'status', 'joined_at', 'can_create_projects'];

    protected function casts(): array
    {
        return ['workspace_id' => 'integer', 'user_id' => 'integer', 'joined_at' => 'datetime', 'can_create_projects' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereIn('role', ['owner', 'admin']);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }
}
