<?php

namespace App\Models\Plans;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPeriod extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return array_fill_keys(['starts_at', 'service_ends_at', 'grace_ends_at', 'superseded_at'], 'immutable_datetime');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'entitlement_version_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Period facts are immutable in Phase 1.'));
        static::deleting(fn () => throw new \LogicException('Period history is retained.'));
    }
}
