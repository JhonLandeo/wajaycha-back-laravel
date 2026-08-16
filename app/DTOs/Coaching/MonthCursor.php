<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

use Carbon\CarbonImmutable;

/**
 * The single declaration of "what day is it, in what month" for pace evaluation.
 *
 * Built once per evaluation run in the reference timezone (design.md D3,
 * America/Lima) so the day-of-month, the days-in-month and the half-open query
 * range used later by TransactionRepository never drift apart. PaceEvaluator only
 * reads `day` and `daysInMonth`; `periodMonth`, `startsAt` and `endsAt` exist so
 * later phases share this one cursor instead of recomputing the calendar.
 */
final class MonthCursor
{
    public function __construct(
        public readonly int $day,
        public readonly int $daysInMonth,
        public readonly CarbonImmutable $periodMonth,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {}

    /**
     * Days left in the month, **today included**.
     *
     * The inclusion is the whole method. On the 31st of a 31-day month a person
     * still has today to spend, so the answer is 1 and not 0 — and 0 is not merely
     * a worse answer, it is a division by zero in every caller that turns a
     * remaining budget into a daily figure. Written here rather than at the call
     * site because this class already exists to keep one declaration of the
     * calendar, and an off-by-one derived twice is an off-by-one in one of them.
     */
    public function daysLeft(): int
    {
        return $this->daysInMonth - $this->day + 1;
    }

    /**
     * Exclusive end of month-to-date: the start of tomorrow.
     *
     * `startsAt`/`endsAt` bound the whole month, which is what a budget is
     * measured against. This bounds what has actually happened so far, which is
     * the only window a comparison against another month can honestly use.
     */
    public function monthToDateEndsAt(): CarbonImmutable
    {
        return $this->startsAt->addDays($this->day);
    }

    /** First instant of the previous month. */
    public function previousMonthStartsAt(): CarbonImmutable
    {
        return $this->periodMonth->subMonthNoOverflow();
    }

    /**
     * How many days of the previous month a fair comparison can cover.
     *
     * The same number as today's day-of-month, except when the previous month was
     * shorter than that: on 30 March there is no 30th of February to stop at, so
     * the window closes at 28 and the two spans stop being equal. Clamping is
     * unavoidable — the fact worth surfacing is that it happened, which is why
     * this is a public figure the message can compare against `day` rather than a
     * detail buried in the date arithmetic.
     */
    public function previousDaysCompared(): int
    {
        return min($this->day, $this->previousMonthStartsAt()->daysInMonth);
    }

    /** Exclusive end of the previous month's comparable window. */
    public function previousMonthToDateEndsAt(): CarbonImmutable
    {
        return $this->previousMonthStartsAt()->addDays($this->previousDaysCompared());
    }

    /**
     * The cursor for the month containing `$now`.
     *
     * Takes the instant rather than calling `now()` so this stays a value object:
     * a DTO that reads the clock cannot be asserted against a fixed date, and
     * every caller here already resolves the reference timezone itself.
     *
     * It exists because two entry points now build this cursor — the coaching
     * sweep and the morning digest — and five lines of calendar arithmetic copied
     * between them is where an off-by-one month lives. `addMonthNoOverflow` is the
     * load-bearing detail: `addMonth()` on January 31 lands in March.
     */
    public static function forInstant(CarbonImmutable $now): self
    {
        $periodMonth = $now->startOfMonth();

        return new self(
            day: $now->day,
            daysInMonth: $now->daysInMonth,
            periodMonth: $periodMonth,
            startsAt: $periodMonth,
            endsAt: $periodMonth->addMonthNoOverflow(),
        );
    }
}
