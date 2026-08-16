<?php

declare(strict_types=1);

/**
 * The arithmetic behind "¿cuánto puedo gastar hoy?", asserted without a database.
 *
 * The figure is one division, which is exactly why it is worth pinning: every way
 * it can be wrong is silent. A denominator off by one, a negative allowance, an
 * annual amount divided by the days left in a month — none of them throw, all of
 * them produce a number the reader will act on.
 */

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\MonthCursor;
use App\Enums\BudgetPeriod;
use App\Services\Coaching\DailyAllowanceCalculator;
use Carbon\CarbonImmutable;

function allowanceCursor(string $date): MonthCursor
{
    return MonthCursor::forInstant(CarbonImmutable::parse($date, 'America/Lima'));
}

function allowanceSnapshot(
    string $name,
    float $budget,
    float $spent,
    string $type = 'expense',
    BudgetPeriod $period = BudgetPeriod::MONTHLY,
    int $id = 1,
): CategoryMonthSnapshot {
    return new CategoryMonthSnapshot(
        categoryId: $id,
        name: $name,
        type: $type,
        monthlyBudget: $budget,
        spent: $spent,
        largestExpenseAmount: 0.0,
        budgetPeriod: $period,
    );
}

it('divide lo que queda entre los dias que quedan', function () {
    // Dia 20 de un mes de 30: quedan 11 dias contando hoy. 550 / 11 = 50.
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Comida', 800.0, 250.0)],
        allowanceCursor('2026-06-20'),
    );

    expect($allowances)->toHaveCount(1)
        ->and($allowances[0]->remaining)->toBe(550.0)
        ->and($allowances[0]->perDay)->toBe(50.0);
});

/**
 * The off-by-one this whole DTO exists to avoid. On the last day of the month a
 * person still has today, so the denominator is 1 — and if it were 0 this would
 * be a division by zero rather than a wrong figure.
 */
it('el ultimo dia del mes todavia cuenta como un dia', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Comida', 800.0, 700.0)],
        allowanceCursor('2026-06-30'),
    );

    expect($allowances[0]->perDay)->toBe(100.0);
});

/**
 * A category that is over budget has no daily allowance, and zero is the honest
 * answer rather than a negative one. `remaining` stays signed so the message can
 * still say by how much.
 */
it('no reparte por dia lo que ya se paso', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Delivery', 300.0, 340.0)],
        allowanceCursor('2026-06-10'),
    );

    expect($allowances[0]->perDay)->toBe(0.0)
        ->and($allowances[0]->remaining)->toBe(-40.0)
        ->and($allowances[0]->hasRoom())->toBeFalse();
});

it('un presupuesto gastado exacto no deja margen', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Delivery', 300.0, 300.0)],
        allowanceCursor('2026-06-10'),
    );

    expect($allowances[0]->perDay)->toBe(0.0)
        ->and($allowances[0]->hasRoom())->toBeFalse();
});

/**
 * The exclusion that is a judgement rather than an impossibility, and therefore
 * the one most likely to be undone by someone who reads it as an oversight.
 * Spreading an annual envelope over the days left in this month invents a rhythm
 * the spending does not have, and reads a year's amount against a month's spend.
 */
it('deja afuera los sobres anuales', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [
            allowanceSnapshot('Seguros', 1200.0, 0.0, period: BudgetPeriod::YEARLY, id: 1),
            allowanceSnapshot('Comida', 600.0, 0.0, id: 2),
        ],
        allowanceCursor('2026-06-15'),
    );

    expect($allowances)->toHaveCount(1)
        ->and($allowances[0]->name)->toBe('Comida');
});

it('deja afuera lo que no tiene presupuesto', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Varios', 0.0, 500.0)],
        allowanceCursor('2026-06-15'),
    );

    expect($allowances)->toBe([]);
});

/**
 * The evaluator refuses non-expense categories itself rather than trusting the
 * repository's filter, and this class inherits the distrust: a second reading of
 * the same rows is a second chance for a filter to be wrong.
 */
it('deja afuera lo que no es gasto', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Sueldo', 5000.0, 0.0, type: 'income')],
        allowanceCursor('2026-06-15'),
    );

    expect($allowances)->toBe([]);
});

/**
 * A budgeted category nobody has spent on is not an edge case here — it is the
 * category with the most room, and dropping it would understate the answer.
 */
it('incluye una categoria presupuestada y sin gasto', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Comida', 300.0, 0.0)],
        allowanceCursor('2026-06-21'),
    );

    // Quedan 10 dias contando hoy.
    expect($allowances[0]->perDay)->toBe(30.0);
});

it('ordena por margen diario, y lo que no tiene margen queda al final', function () {
    $allowances = (new DailyAllowanceCalculator)->calculate(
        [
            allowanceSnapshot('Delivery', 300.0, 340.0, id: 1),
            allowanceSnapshot('Comida', 800.0, 250.0, id: 2),
            allowanceSnapshot('Transporte', 300.0, 200.0, id: 3),
        ],
        allowanceCursor('2026-06-20'),
    );

    expect(array_map(fn ($a): string => $a->name, $allowances))
        ->toBe(['Comida', 'Transporte', 'Delivery']);
});

it('no devuelve nada cuando no hay snapshots', function () {
    expect((new DailyAllowanceCalculator)->calculate([], allowanceCursor('2026-06-20')))->toBe([]);
});

/**
 * February is the month where a hardcoded 30 or 31 would go unnoticed for eleven
 * months a year.
 */
it('usa los dias reales del mes, no un mes de treinta', function () {
    $cursor = allowanceCursor('2026-02-20');

    // 2026 no es bisiesto: febrero tiene 28 dias, quedan 9 contando hoy.
    expect($cursor->daysLeft())->toBe(9);

    $allowances = (new DailyAllowanceCalculator)->calculate(
        [allowanceSnapshot('Comida', 900.0, 0.0)],
        $cursor,
    );

    expect($allowances[0]->perDay)->toBe(100.0);
});
