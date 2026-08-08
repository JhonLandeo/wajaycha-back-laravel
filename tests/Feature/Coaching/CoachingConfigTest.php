<?php

declare(strict_types=1);

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceThresholds;
use App\Services\Coaching\PaceEvaluator;
use Carbon\CarbonImmutable;

/**
 * Task 4.1: config/coaching.php extended with the remaining pace thresholds, the
 * kill switch, and the reachable channel list (design.md D1, D4, §5.1). Values
 * only — PaceEvaluator never reads config() itself.
 */
it('defaults every threshold task 4.1 introduces', function () {
    expect(config('coaching.min_day_for_projection'))->toBe(5)
        ->and(config('coaching.overrun_margin'))->toBe(0.10)
        ->and(config('coaching.lumpy_share'))->toBe(0.50)
        ->and(config('coaching.max_observations_per_message'))->toBe(3)
        ->and(config('coaching.channels'))->toBe(['telegram'])
        ->and(config('coaching.enabled'))->toBeTrue();
});

it('builds PaceThresholds straight from config with the same defaults PaceEvaluator was tested against', function () {
    $thresholds = new PaceThresholds(
        minDayForProjection: config('coaching.min_day_for_projection'),
        overrunMargin: config('coaching.overrun_margin'),
        lumpyShare: config('coaching.lumpy_share'),
        maxObservations: config('coaching.max_observations_per_message'),
    );

    expect($thresholds->minDayForProjection)->toBe(5)
        ->and($thresholds->overrunMargin)->toBe(0.10)
        ->and($thresholds->lumpyShare)->toBe(0.50)
        ->and($thresholds->maxObservations)->toBe(3);
});

/**
 * The kill switch is honoured, not merely present: `COACHING_MAX_OBSERVATIONS=0`
 * feeds PaceThresholds::$maxObservations, and PaceEvaluator::evaluate() (already
 * built in phase 1) truncates its output to that value regardless of how many
 * categories are over pace. This proves the mechanism with real, already-merged
 * production code — no FinancialCoachingService required to demonstrate it.
 */
it('honours COACHING_MAX_OBSERVATIONS as a real kill dial on the already-built evaluator', function () {
    config(['coaching.max_observations_per_message' => 0]);

    $thresholds = new PaceThresholds(
        minDayForProjection: config('coaching.min_day_for_projection'),
        overrunMargin: config('coaching.overrun_margin'),
        lumpyShare: config('coaching.lumpy_share'),
        maxObservations: config('coaching.max_observations_per_message'),
    );

    $periodMonth = CarbonImmutable::create(2026, 8, 1);
    $cursor = new MonthCursor(
        day: 12,
        daysInMonth: 31,
        periodMonth: $periodMonth,
        startsAt: $periodMonth,
        endsAt: $periodMonth->addMonthNoOverflow(),
    );
    $snapshot = new CategoryMonthSnapshot(
        categoryId: 1,
        name: 'Comida',
        type: 'expense',
        monthlyBudget: 400.0,
        spent: 450.0,
        largestExpenseAmount: 0.0,
    );

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, $thresholds);

    expect($observations)->toBe([]);
});

it('still returns the observation once the dial is back at its default', function () {
    $thresholds = new PaceThresholds(
        minDayForProjection: config('coaching.min_day_for_projection'),
        overrunMargin: config('coaching.overrun_margin'),
        lumpyShare: config('coaching.lumpy_share'),
        maxObservations: config('coaching.max_observations_per_message'),
    );

    $periodMonth = CarbonImmutable::create(2026, 8, 1);
    $cursor = new MonthCursor(
        day: 12,
        daysInMonth: 31,
        periodMonth: $periodMonth,
        startsAt: $periodMonth,
        endsAt: $periodMonth->addMonthNoOverflow(),
    );
    $snapshot = new CategoryMonthSnapshot(
        categoryId: 1,
        name: 'Comida',
        type: 'expense',
        monthlyBudget: 400.0,
        spent: 450.0,
        largestExpenseAmount: 0.0,
    );

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, $thresholds);

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->band)->toBe('over_budget');
});
