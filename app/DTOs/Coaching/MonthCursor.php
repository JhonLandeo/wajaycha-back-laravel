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
}
