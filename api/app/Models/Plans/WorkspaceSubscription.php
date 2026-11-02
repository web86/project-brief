<?php

namespace App\Models\Plans;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSubscription extends Model
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
        return ['revision' => 'integer', 'approved_extra_seats' => 'integer', 'next_boundary_at' => 'immutable_datetime'];
    }

    public function currentPeriod(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPeriod::class, 'current_period_id');
    }
}
