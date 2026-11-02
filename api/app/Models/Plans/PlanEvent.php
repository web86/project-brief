<?php

namespace App\Models\Plans;

use Illuminate\Database\Eloquent\Model;

class PlanEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['diff_json' => 'array', 'result_json' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit is append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit is append-only.'));
    }
}
