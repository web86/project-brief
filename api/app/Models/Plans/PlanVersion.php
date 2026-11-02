<?php

namespace App\Models\Plans;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanVersion extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['translations_json' => 'array', 'price_json' => 'array', 'developer_seats_json' => 'array', 'published_at' => 'immutable_datetime', 'revision' => 'integer', 'number' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected static function booted(): void
    {
        $protect = function (self $version): void {
            if ($version->getRawOriginal('state') === 'published') {
                throw new \LogicException('Published version is immutable.');
            }
        };
        static::updating($protect);
        static::deleting($protect);
    }
}
