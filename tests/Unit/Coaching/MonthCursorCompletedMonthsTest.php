<?php

declare(strict_types=1);

/**
 * The window the fixed-versus-chosen answer measures over.
 *
 * Complete months only. Including the month in progress would show every category
 * dropping at once, and anything reading the series for how much a category varies
 * would take that artefact for the real thing — calling a rent paid on the 5th
 * volatile for the first four days of every month.
 */

use App\DTOs\Coaching\MonthCursor;
use Carbon\CarbonImmutable;

function completedCursor(string $date): MonthCursor
{
    return MonthCursor::forInstant(CarbonImmutable::parse($date, 'America/Lima'));
}

function completedStarts(string $date, int $count): array
{
    return array_map(
        fn (CarbonImmutable $start): string => $start->toDateString(),
        completedCursor($date)->completedMonthStarts($count),
    );
}

it('devuelve los meses completos anteriores, del mas viejo al mas nuevo', function () {
    expect(completedStarts('2026-07-10', 6))
        ->toBe(['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01']);
});

it('nunca incluye el mes en curso', function () {
    expect(completedStarts('2026-07-31', 3))->not->toContain('2026-07-01');
});

it('cruza el año hacia atras', function () {
    expect(completedStarts('2026-02-15', 4))
        ->toBe(['2025-10-01', '2025-11-01', '2025-12-01', '2026-01-01']);
});

/**
 * `subMonthsNoOverflow` and not `subMonths`: the cursor's `periodMonth` is always
 * the first of a month so overflow cannot bite today, but the day it is built from
 * anything else, `subMonths` on a 31st lands in the wrong month entirely.
 */
it('no se corre de mes en los meses cortos', function () {
    expect(completedStarts('2026-03-31', 2))->toBe(['2026-01-01', '2026-02-01']);
});

it('devuelve vacio cuando no se piden meses', function () {
    expect(completedStarts('2026-07-10', 0))->toBe([]);
});
