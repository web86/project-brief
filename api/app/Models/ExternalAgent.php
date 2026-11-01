<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExternalAgent extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = ['kind' => 'agent'];

    protected $fillable = ['workspace_id', 'name', 'description', 'kind', 'active', 'permissions', 'instructions'];

    protected function casts(): array
    {
        return ['workspace_id' => 'integer', 'active' => 'boolean', 'permissions' => 'array', 'last_success_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function publicState(): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'description' => $this->description, 'kind' => $this->kind, 'active' => $this->active && ! $this->trashed(), 'lastActivityAt' => $this->last_success_at?->toISOString()];
    }
}
