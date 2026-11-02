<?php

namespace App\Models\Plans;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformPolicyVersion extends Model
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
        return ['eligibility_json' => 'array', 'security_termination_json' => 'array', 'retention_json' => 'array', 'revision' => 'integer', 'trial_days' => 'integer', 'grace_days' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        $protect = function (self $policy): void {
            if ($policy->getRawOriginal('state') === 'published') {
                throw new \LogicException('Published policy is immutable.');
            }
        };
        static::updating($protect);
        static::deleting($protect);
    }
}
