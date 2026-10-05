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

    protected $fillable = ['project_id', 'name', 'email', 'active', 'preferred_locale'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
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
