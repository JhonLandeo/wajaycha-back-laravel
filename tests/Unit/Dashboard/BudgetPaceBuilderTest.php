<?php

declare(strict_types=1);

/**
 * The arithmetic behind "¿cómo va el mes contra el presupuesto?", asserted
 * without a database.
 *
 * The projection has two ways to be wrong and neither throws: extrapolating from
 * a sample too small to be a rate, or comparing a partial month against a full
 * month's budget. Both produce a number the reader will act on, which is why the
 * day count and the withholding rule are pinned here.
 */

use App\Services\Dashboard\BudgetPaceBuilder;

function pace(
    array $dailyExpense,
    float $budget,
    int $daysInMonth,
    int $daysElapsed,
    bool $isCurrent = true,
    bool $isComplete = false,
    int $minDay = 5,
    float $margin = 0.10,
): \App\DTOs\Dashboard\BudgetPace {
    return (new BudgetPaceBuilder)->build(
        year: 2026,
        month: 6,
        dailyExpense: $dailyExpense,
        monthlyBudget: $budget,
        categoryCount: 2,
        daysInMonth: $daysInMonth,
        daysElapsed: $daysElapsed,
        isCurrentMonth: $isCurrent,
        isCompleteMonth: $isComplete,
        minDayForProjection: $minDay,
        overrunMargin: $margin,
    );
}

it('acumula el gasto y proyecta el cierre del mes', function () {
    $result = pace(
        [1 => 100.0, 2 => 50.0, 9 => 50.0],
        budget: 1000.0,
        daysInMonth: 30,
        daysElapsed: 10,
    );

    expect($result->spent)->toBe(200.0)
        ->and($result->daily)->toHaveCount(10)
        ->and($result->daily[2])->toBe(['day' => 3, 'amount' => 0.0, 'cumulative' => 150.0])
        ->and($result->daily[9])->toBe(['day' => 10, 'amount' => 0.0, 'cumulative' => 200.0])
        ->and($result->projected)->toBe(600.0)
        ->and($result->status)->toBe('on_track');
});

it('calcula el presupuesto que deberia estar gastado a esta altura', function () {
    $result = pace([], budget: 1000.0, daysInMonth: 30, daysElapsed: 10);

    expect($result->paceBudgetToDate)->toBe(333.33)
        ->and($result->percentageUsed)->toBe(0.0);
});

/**
 * On day three, `spent × days / 3` is one movement multiplied by ten. The
 * coaching subsystem refuses to project before its own minimum day; the chart
 * must not say what the coach would not.
 */
it('no proyecta antes del dia minimo', function () {
    $result = pace([1 => 120.0], budget: 1000.0, daysInMonth: 30, daysElapsed: 3);

    expect($result->projected)->toBeNull()
        ->and($result->status)->toBe('on_track');
});

it('marca over cuando ya se paso del presupuesto', function () {
    $result = pace([1 => 150.0], budget: 100.0, daysInMonth: 30, daysElapsed: 10);

    expect($result->remaining)->toBe(-50.0)
        ->and($result->status)->toBe('over');
});

it('marca watch cuando la proyeccion supera el presupuesto mas el margen', function () {
    // 120 * 30 / 10 = 360. El margen del 10% deja el techo en 330.
    $result = pace([1 => 120.0], budget: 300.0, daysInMonth: 30, daysElapsed: 10);

    expect($result->projected)->toBe(360.0)
        ->and($result->status)->toBe('watch');
});

it('no proyecta un mes ya cerrado y lo juzga por lo gastado', function () {
    $result = pace(
        [1 => 2500.0],
        budget: 3000.0,
        daysInMonth: 30,
        daysElapsed: 30,
        isCurrent: false,
        isComplete: true,
    );

    expect($result->projected)->toBeNull()
        ->and($result->daily)->toHaveCount(30)
        ->and($result->status)->toBe('on_track');
});

it('no inventa una serie para un mes que no empezo', function () {
    $result = pace(
        [],
        budget: 1000.0,
        daysInMonth: 31,
        daysElapsed: 0,
        isCurrent: false,
        isComplete: false,
    );

    expect($result->daily)->toBe([])
        ->and($result->status)->toBe('no_data');
});

it('dice no_budget cuando no hay nada que ritmar', function () {
    $result = pace([1 => 40.0], budget: 0.0, daysInMonth: 30, daysElapsed: 10);

    expect($result->status)->toBe('no_budget')
        ->and($result->percentageUsed)->toBe(0.0);
});
