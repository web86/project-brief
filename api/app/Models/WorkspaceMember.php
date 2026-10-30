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

    protected static function booted(): void
    {
        $invalidate = function (self $member): void {
            $connection = $member->getConnection();
            if ($connection->getSchemaBuilder()->hasTable('account_security_challenges')) {
                $connection->table('account_security_challenges')->whereIn('user_id', array_filter([$member->user_id, $member->getOriginal('user_id')]))->delete();
            }
        };
        static::saving(function (self $member) use ($invalidate): void {
            if (! $member->exists || $member->isDirty(['role', 'status', 'can_create_projects', 'is_project_superadmin', 'workspace_id', 'user_id'])) {
                $invalidate($member);
            }
        });
        static::deleting($invalidate);
    }

    protected $fillable = ['workspace_id', 'user_id', 'role', 'status', 'joined_at', 'can_create_projects', 'is_project_superadmin', 'display_name'];

    protected function casts(): array
    {
        return ['workspace_id' => 'integer', 'user_id' => 'integer', 'joined_at' => 'datetime', 'is_project_superadmin' => 'boolean', 'can_create_projects' => 'boolean'];
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
