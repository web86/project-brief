<?php

namespace App\Models\Plans;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if ($plan->isDirty('code') && $plan->versions()->where('state', 'published')->exists()) {
                throw new \LogicException('Published plan code is immutable.');
            }
        });
    }
}
