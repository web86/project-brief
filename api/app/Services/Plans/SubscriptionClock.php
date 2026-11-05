<?php

namespace App\Services\Plans;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SubscriptionClock
{
    /** Pure contract: this method neither verifies an owner nor starts a trial. */
    public function period(string $kind, CarbonImmutable $startsAt, ?int $serviceDays = null, ?int $graceDays = null): array
    {
        if (! in_array($kind, ['free', 'compatibility', 'trial', 'paid'], true)) {
            throw new InvalidArgumentException('Unknown period kind.');
        }
        $start = $startsAt->utc();
        if (in_array($kind, ['free', 'compatibility'], true)) {
            if ($serviceDays !== null || $graceDays !== null) {
                throw new InvalidArgumentException('Free and compatibility periods are open ended.');
            }

            return ['kind' => $kind, 'starts_at' => $start, 'service_ends_at' => null, 'grace_ends_at' => null];
        }
        if ($serviceDays === null || $serviceDays < 1 || $serviceDays > 36600 || ($kind === 'paid' && ($graceDays === null || $graceDays < 0 || $graceDays > 366))) {
            throw new InvalidArgumentException('Invalid bounded period duration.');
        }
        $end = $start->addSeconds($serviceDays * 86400);

        return ['kind' => $kind, 'starts_at' => $start, 'service_ends_at' => $end, 'grace_ends_at' => $kind === 'paid' ? $end->addSeconds($graceDays * 86400) : null];
    }

    public function manualPeriod(string $kind, CarbonImmutable $startsAt, ?string $endDate, int $graceDays): array
    {
        if ($kind === 'free' && $endDate === null) {
            return $this->period('free', $startsAt);
        }
        abort_unless($kind === 'paid' && $endDate !== null, 422, 'manual_assignment_end_date_required');
        $end = CarbonImmutable::parse($endDate, 'UTC')->startOfDay()->addDay();
        abort_unless($end > $startsAt && $end <= $startsAt->addDays(36600), 422, 'manual_assignment_end_date_invalid');

        return ['kind' => 'paid', 'starts_at' => $startsAt, 'service_ends_at' => $end, 'grace_ends_at' => $end->addDays($graceDays)];
    }

    public function phase(array $period, CarbonImmutable $at): array
    {
        $kind = $period['kind'] ?? null;
        if (! in_array($kind, ['free', 'compatibility', 'trial', 'paid'], true) || ! ($period['starts_at'] ?? null) instanceof CarbonImmutable) {
            throw new InvalidArgumentException('Incomplete period facts.');
        }
        $start = $period['starts_at']->utc();
        $end = $period['service_ends_at'] ?? null;
        $grace = $period['grace_ends_at'] ?? null;
        if (in_array($kind, ['trial', 'paid'], true) && (! $end instanceof CarbonImmutable || $end <= $start || ($kind === 'trial' && $grace !== null) || ($kind === 'paid' && (! $grace instanceof CarbonImmutable || $grace < $end)))) {
            throw new InvalidArgumentException('Incomplete or invalid period boundary.');
        }
        if (in_array($kind, ['free', 'compatibility'], true) && ($end !== null || $grace !== null)) {
            throw new InvalidArgumentException('Open ended period has an invalid boundary.');
        }
        if ($at < $start) {
            return ['phase' => 'pending', 'valid_until' => $start];
        }
        if ($end === null || $at < $end) {
            return ['phase' => $kind, 'valid_until' => $end];
        }
        if ($kind === 'paid' && $at < $grace) {
            return ['phase' => 'grace', 'valid_until' => $grace];
        }

        return ['phase' => 'fallback_required', 'valid_until' => null];
    }
}
