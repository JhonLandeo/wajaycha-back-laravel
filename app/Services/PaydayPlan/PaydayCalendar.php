<?php

declare(strict_types=1);

namespace App\Services\PaydayPlan;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Every date decision of the payday plan, in `America/Lima` (PD-3).
 *
 * Pure: the clock arrives as an argument. `payday_day` is capped at 28, so every
 * month has a payday date and no end-of-month clamping exists.
 */
final class PaydayCalendar
{
    public const TIMEZONE = 'America/Lima';

    /** The Lima calendar date of an instant, at 00:00. */
    public function today(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /** First day of the Lima month the instant falls in. */
    public function periodMonth(CarbonInterface $now): CarbonImmutable
    {
        return $this->today($now)->startOfMonth();
    }

    public function paydayOn(CarbonInterface $now, int $paydayDay): CarbonImmutable
    {
        return $this->periodMonth($now)->addDays($paydayDay - 1);
    }

    /** Payday is today or already passed this month: a missed run is caught up, not skipped. */
    public function isEligible(CarbonInterface $now, int $paydayDay): bool
    {
        return $this->today($now)->greaterThanOrEqualTo($this->paydayOn($now, $paydayDay));
    }

    /**
     * Whether an unsent plan message may still be retried: up to `$graceDays`
     * Lima calendar days after the day of the first attempt, inclusive.
     */
    public function isWithinGrace(CarbonInterface $now, CarbonInterface $firstAttemptAt, int $graceDays): bool
    {
        return $this->today($now)->lessThanOrEqualTo($this->today($firstAttemptAt)->addDays($graceDays));
    }

    /** The day after the instant: when the single reminder is due. */
    public function reminderDueOn(CarbonInterface $deliveredAt): CarbonImmutable
    {
        return $this->today($deliveredAt)->addDay();
    }

    public function isCurrentMonth(CarbonInterface $periodMonth, CarbonInterface $now): bool
    {
        // A period month is a calendar date, not an instant: it is compared as
        // written, never shifted through a timezone.
        return $periodMonth->format('Y-m') === $this->periodMonth($now)->format('Y-m');
    }
}
