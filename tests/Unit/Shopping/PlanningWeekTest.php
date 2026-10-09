<?php

declare(strict_types=1);

use App\DTOs\Shopping\PlanningWeek;
use Carbon\CarbonImmutable;

/**
 * design.md "Inputs": `PlanningWeek` is Monday-to-Sunday in `America/Lima`,
 * built from `asOf` by the Action (q1) — the `ParetoWindow::forFilter()`
 * shape, a value object with a named constructor. No database, no clock
 * inside the DTO itself — `asOf` always arrives as an argument.
 */
it('anchors a midweek date to its own Monday-to-Sunday boundaries', function () {
    // 2026-09-24 is a Thursday in America/Lima.
    $week = PlanningWeek::forDate(CarbonImmutable::parse('2026-09-24 15:00:00', 'America/Lima'));

    expect($week->startsOn->toDateString())->toBe('2026-09-21')
        ->and($week->endsOn->toDateString())->toBe('2026-09-27')
        ->and($week->startsOn->dayOfWeekIso)->toBe(1)
        ->and($week->endsOn->dayOfWeekIso)->toBe(7);
});

it('keeps a Sunday asOf inside its own week rather than rolling to the next one', function () {
    // 2026-09-27 is a Sunday in America/Lima.
    $week = PlanningWeek::forDate(CarbonImmutable::parse('2026-09-27 08:00:00', 'America/Lima'));

    expect($week->startsOn->toDateString())->toBe('2026-09-21')
        ->and($week->endsOn->toDateString())->toBe('2026-09-27');
});

it('carries the original instant as asOf, untouched by the week boundaries', function () {
    $asOf = CarbonImmutable::parse('2026-09-24 15:00:00', 'America/Lima');
    $week = PlanningWeek::forDate($asOf);

    expect($week->asOf->equalTo($asOf))->toBeTrue();
});
