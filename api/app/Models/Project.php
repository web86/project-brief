<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'website_url', 'client_name', 'client_email', 'currency', 'status', 'sections'];

    protected function casts(): array
    {
        return ['sections' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function accessTokens()
    {
        return $this->hasMany(ProjectAccessToken::class);
    }
}
