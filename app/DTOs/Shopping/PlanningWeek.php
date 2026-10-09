<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use Carbon\CarbonImmutable;

/**
 * The Monday-to-Sunday window the weekly plan is computed over, in
 * `America/Lima` (design.md "Inputs"). A value object with a named
 * constructor, the `ParetoWindow::forFilter()` shape — the Action supplies
 * the clock via `$asOf`, never `now()` read from inside the DTO.
 */
final class PlanningWeek
{
    private function __construct(
        public readonly CarbonImmutable $asOf,
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
    ) {}

    public static function forDate(CarbonImmutable $asOf): self
    {
        $local = $asOf->setTimezone('America/Lima');

        return new self(
            asOf: $asOf,
            startsOn: $local->startOfWeek(CarbonImmutable::MONDAY),
            endsOn: $local->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
        );
    }
}
