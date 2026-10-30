<?php

namespace App\Models;

use App\Services\AgreementTemplates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Workspace extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (self $workspace): void {
            if ($workspace->administrator_agreement === null && Schema::connection($workspace->getConnectionName())->hasColumn('workspaces', 'administrator_agreement')) {
                $workspace->administrator_agreement = app(AgreementTemplates::class)->defaults();
            }
        });
    }

    protected $fillable = ['name', 'branding', 'administrator_agreement'];

    protected $hidden = ['branding', 'administrator_agreement'];

    protected function casts(): array
    {
        return ['branding' => 'array', 'administrator_agreement' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
