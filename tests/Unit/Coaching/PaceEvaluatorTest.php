<?php

declare(strict_types=1);

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceThresholds;
use App\Enums\BudgetPeriod;
use App\Services\Coaching\PaceEvaluator;
use Carbon\CarbonImmutable;

/**
 * PaceEvaluator is pure PHP: no database, no facades, no now(). Every test builds its
 * inputs from plain values, per design.md §5.1's decision table and D1's promise that
 * this evaluator is "unit-tested with no database" mechanically, not aspirationally.
 */
function paceCursor(int $day, int $daysInMonth, int $month = 8): MonthCursor
{
    $periodMonth = CarbonImmutable::create(2026, $month, 1);

    return new MonthCursor(
        day: $day,
        daysInMonth: $daysInMonth,
        periodMonth: $periodMonth,
        startsAt: $periodMonth,
        endsAt: $periodMonth->addMonthNoOverflow(),
    );
}

function paceThresholds(int $maxObservations = 3): PaceThresholds
{
    return new PaceThresholds(
        minDayForProjection: 5,
        overrunMargin: 0.10,
        lumpyShare: 0.50,
        maxObservations: $maxObservations,
        envelopeConsumedShare: 0.80,
        envelopeMinMonthsRemaining: 2,
    );
}

function paceSnapshot(
    int $categoryId = 1,
    string $name = 'Comida',
    string $type = 'expense',
    float $monthlyBudget = 400.0,
    float $spent = 340.0,
    float $largestExpenseAmount = 0.0,
    BudgetPeriod $budgetPeriod = BudgetPeriod::MONTHLY,
    float $spentInYear = 0.0,
): CategoryMonthSnapshot {
    return new CategoryMonthSnapshot(
        categoryId: $categoryId,
        name: $name,
        type: $type,
        monthlyBudget: $monthlyBudget,
        spent: $spent,
        largestExpenseAmount: $largestExpenseAmount,
        budgetPeriod: $budgetPeriod,
        spentInYear: $spentInYear,
    );
}

/** A yearly envelope: the budget amount is annual, and `spentInYear` is what it is measured against. */
function envelopeSnapshot(
    float $annualBudget = 1200.0,
    float $spentThisMonth = 350.0,
    float $spentInYear = 350.0,
    string $name = 'Salud',
): CategoryMonthSnapshot {
    return paceSnapshot(
        name: $name,
        monthlyBudget: $annualBudget,
        spent: $spentThisMonth,
        // A single consultation is always 100% of its own month: lumpiness is
        // the expected shape of an envelope, never a reason to stay quiet.
        largestExpenseAmount: $spentThisMonth,
        budgetPeriod: BudgetPeriod::YEARLY,
        spentInYear: $spentInYear,
    );
}

it('projects a month-end overrun when the same level appears mid-month (design.md canonical example)', function () {
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 340.0);
    $cursor = paceCursor(day: 12, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toHaveCount(1);
    expect($observations[0]->band)->toBe('projected_over');
    expect($observations[0]->projected)->toBe(340.0 * 31 / 12);
    expect($observations[0]->isLumpy)->toBeFalse();
    expect($observations[0]->subjectKey)->toBe('category:1');
});

it('stays silent for the same level late in the month', function () {
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 340.0);
    $cursor = paceCursor(day: 28, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('speaks about actual overspend even on day 3, before projection is allowed', function () {
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 420.0);
    $cursor = paceCursor(day: 3, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toHaveCount(1);
    expect($observations[0]->band)->toBe('over_budget');
    expect($observations[0]->projected)->toBeNull();
});

it('never projects before day 5, even when the projection would look alarming', function () {
    $budget = 400.0;
    $day = 3;
    $daysInMonth = 30;
    $projectedTarget = $budget * 1.12; // would be a 12%-over projection if it were computed
    $spent = $projectedTarget * $day / $daysInMonth;

    $snapshot = paceSnapshot(monthlyBudget: $budget, spent: $spent);
    $cursor = paceCursor(day: $day, daysInMonth: $daysInMonth);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('does not project on day 1, where multiplying by the full month manufactures alarm', function () {
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 50.0);
    $cursor = paceCursor(day: 1, daysInMonth: 30);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('stays silent when a day-12 projection is only 4% over budget, under the 10% margin', function () {
    $budget = 400.0;
    $day = 12;
    $daysInMonth = 31;
    $projectedTarget = $budget * 1.04;
    $spent = $projectedTarget * $day / $daysInMonth;

    $snapshot = paceSnapshot(monthlyBudget: $budget, spent: $spent);
    $cursor = paceCursor(day: $day, daysInMonth: $daysInMonth);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('speaks when the projected overrun sits exactly on the 10% margin boundary', function () {
    $budget = 400.0;
    $day = 10;
    $daysInMonth = 20;
    $projectedTarget = $budget * 1.10; // exactly the boundary
    $spent = $projectedTarget * $day / $daysInMonth;

    $snapshot = paceSnapshot(monthlyBudget: $budget, spent: $spent);
    $cursor = paceCursor(day: $day, daysInMonth: $daysInMonth);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toHaveCount(1);
    expect($observations[0]->band)->toBe('projected_over');
});

it('stays silent when the projected overrun sits just under the 10% margin boundary', function () {
    $budget = 400.0;
    $day = 10;
    $daysInMonth = 20;
    $projectedTarget = ($budget * 1.10) - 0.01; // just short of the boundary
    $spent = $projectedTarget * $day / $daysInMonth;

    $snapshot = paceSnapshot(monthlyBudget: $budget, spent: $spent);
    $cursor = paceCursor(day: $day, daysInMonth: $daysInMonth);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('reports level instead of pace when one transaction is 70% of month-to-date spend and the category is already over budget', function () {
    $spent = 420.0;
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: $spent, largestExpenseAmount: 0.70 * $spent);
    $cursor = paceCursor(day: 20, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toHaveCount(1);
    expect($observations[0]->band)->toBe('over_budget');
    expect($observations[0]->isLumpy)->toBeTrue();
    expect($observations[0]->projected)->toBeNull();
});

it('stays silent when one transaction is 70% of month-to-date spend but the category is still under budget', function () {
    $spent = 200.0;
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: $spent, largestExpenseAmount: 0.70 * $spent);
    $cursor = paceCursor(day: 20, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('suppresses a projection that would otherwise fire once the largest transaction reaches exactly 50% of spend', function () {
    $spent = 200.0;
    $snapshot = paceSnapshot(monthlyBudget: 300.0, spent: $spent, largestExpenseAmount: 0.50 * $spent);
    $cursor = paceCursor(day: 10, daysInMonth: 20); // projected = 400, 33% over budget if not lumpy

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('still projects when the largest transaction is just under the 50% lumpiness boundary', function () {
    $spent = 200.0;
    $snapshot = paceSnapshot(monthlyBudget: 300.0, spent: $spent, largestExpenseAmount: (0.50 * $spent) - 1.0);
    $cursor = paceCursor(day: 10, daysInMonth: 20); // projected = 400, 33% over budget

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toHaveCount(1);
    expect($observations[0]->band)->toBe('projected_over');
    expect($observations[0]->isLumpy)->toBeFalse();
});

it('a category exactly at its budget on the last day of the month is not an overrun', function () {
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 400.0);
    $cursor = paceCursor(day: 31, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('never evaluates an income category, even one deep over its allocation', function () {
    $snapshot = paceSnapshot(type: 'income', monthlyBudget: 400.0, spent: 900.0);
    $cursor = paceCursor(day: 20, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('skips a category with zero spend', function () {
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 0.0);
    $cursor = paceCursor(day: 20, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('never raises a pace observation for a zero-budget category with spend — blindness is the caller\'s concern', function () {
    $snapshot = paceSnapshot(monthlyBudget: 0.0, spent: 250.0);
    $cursor = paceCursor(day: 20, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('stays silent for a zero-budget category with zero spend', function () {
    $snapshot = paceSnapshot(monthlyBudget: 0.0, spent: 0.0);
    $cursor = paceCursor(day: 20, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toBeEmpty();
});

it('orders observations by severity and caps the result at maxObservations', function () {
    $cursor = paceCursor(day: 20, daysInMonth: 31);
    $thresholds = paceThresholds(maxObservations: 3);

    $snapshots = [
        paceSnapshot(categoryId: 1, name: 'Ratio 2.0', monthlyBudget: 100.0, spent: 200.0), // over_budget
        paceSnapshot(categoryId: 2, name: 'Ratio 3.0', monthlyBudget: 100.0, spent: 300.0), // over_budget, worst
        paceSnapshot(categoryId: 3, name: 'Ratio 1.5', monthlyBudget: 100.0, spent: 150.0), // over_budget
        paceSnapshot(categoryId: 4, name: 'Projected A', monthlyBudget: 100.0, spent: 80.0), // projected_over, ratio 1.24
        paceSnapshot(categoryId: 5, name: 'Projected B', monthlyBudget: 100.0, spent: 75.0), // projected_over, ratio 1.1625
    ];

    $observations = (new PaceEvaluator)->evaluate($snapshots, $cursor, $thresholds);

    expect($observations)->toHaveCount(3);
    expect(array_map(fn ($o) => $o->categoryId, $observations))->toBe([2, 1, 3]);
    expect(array_map(fn ($o) => $o->band, $observations))->toBe(['over_budget', 'over_budget', 'over_budget']);
});

it('stays silent on day 4, one day below the projection floor', function () {
    // 100 gastados de 400 el dia 4 proyectan 775: mas del doble del presupuesto. El
    // piso existe justamente para que esa aritmetica no fabrique una alarma.
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 100.0);
    $cursor = paceCursor(day: 4, daysInMonth: 31);

    expect((new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds()))->toBe([]);
});

it('projects on day 5 exactly, the first day the floor allows', function () {
    // Mismos numeros, un dia despues. El corte es "antes del 5 no", no "desde el 6".
    $snapshot = paceSnapshot(monthlyBudget: 400.0, spent: 100.0);
    $cursor = paceCursor(day: 5, daysInMonth: 31);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], $cursor, paceThresholds());

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->band)->toBe('projected_over');
});

it('pone cualquier over_budget por encima de cualquier projected_over, sin importar el score', function () {
    // El caso que distingue el orden real del orden por puntaje: un over_budget apenas
    // pasado (1.01) contra un projected_over disparado (3.1). Si el rango no mandara,
    // el segundo saldria primero.
    // El que ya se paso lo hizo por un peso: score 1.01.
    $apenasPasado = paceSnapshot(categoryId: 1, name: 'Apenas', monthlyBudget: 100.0, spent: 101.0);
    // El otro sigue debajo del presupuesto, pero proyecta 1.53 veces: score mas alto.
    $proyeccionAlta = paceSnapshot(categoryId: 2, name: 'Proyeccion', monthlyBudget: 100.0, spent: 99.0);

    $observations = (new PaceEvaluator)->evaluate(
        [$proyeccionAlta, $apenasPasado],
        paceCursor(day: 20, daysInMonth: 31),
        paceThresholds(),
    );

    expect($observations)->toHaveCount(2)
        ->and($observations[0]->band)->toBe('over_budget')
        ->and($observations[0]->categoryId)->toBe(1)
        ->and($observations[1]->band)->toBe('projected_over')
        ->and($observations[1]->categoryId)->toBe(2)
        // La prueba de que manda el rango y no el puntaje: el segundo proyecta mas
        // veces su presupuesto que lo que el primero lo excedio.
        ->and($observations[1]->projected / 100.0)->toBeGreaterThan($observations[0]->spent / 100.0);
});

/*
|--------------------------------------------------------------------------
| Yearly envelopes
|--------------------------------------------------------------------------
|
| A budget whose natural unit is the year, evaluated by depletion instead of
| pace. The first test is the regression this whole branch exists for.
|
*/

it('no dice que pasaste el presupuesto por una consulta medica que cabe de sobra en el sobre anual', function () {
    // Salud: S/ 1200 al año, una consulta de S/ 350 el día 15. Contra la
    // columna leída como mensual esto era spent(350) > budget(1200/12=100)
    // y salía "ya pasaste el presupuesto" — falso, y una falsa alarma es
    // lo que hace que el usuario silencie al coach para siempre.
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 350.0, spentInYear: 350.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 31), paceThresholds());

    expect($observations)->toBeEmpty();
});

it('habla de exceso solo cuando el gasto del año pasa el sobre del año', function () {
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 350.0, spentInYear: 1350.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 31), paceThresholds());

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->band)->toBe('envelope_exceeded')
        // `spent` lleva el acumulado del año, no el del mes: comparte unidad
        // con `budget`, asi que spent/budget significa algo sin contexto.
        ->and($observations[0]->spent)->toBe(1350.0)
        ->and($observations[0]->budget)->toBe(1200.0);
});

it('nunca proyecta un sobre anual, ni siquiera cuando lo excede', function () {
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 1350.0, spentInYear: 1350.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 31), paceThresholds());

    // 1350 × 31 / 15 = 2790 seria la proyeccion mensual. Multiplicar un gasto
    // esporadico por lo que queda del mes no es una advertencia, es ruido.
    expect($observations[0]->projected)->toBeNull();
});

it('avisa que el sobre esta consumido cuando queda año por delante', function () {
    // Agosto: mes 8, quedan 4 meses. 960 / 1200 = 80%, justo en el umbral.
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 300.0, spentInYear: 960.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 31), paceThresholds());

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->band)->toBe('envelope_consumed')
        ->and($observations[0]->monthsRemaining)->toBe(4)
        ->and($observations[0]->projected)->toBeNull();
});

it('se calla sobre un sobre consumido cuando ya no queda año para hacer nada', function () {
    // Noviembre: mes 11, queda 1 mes, por debajo del piso de 2. El dato sigue
    // siendo cierto; lo que ya no existe es el margen para que sirva.
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 300.0, spentInYear: 1020.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 30, month: 11), paceThresholds());

    expect($observations)->toBeEmpty();
});

it('se calla cuando el sobre anual todavia esta lejos de agotarse', function () {
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 350.0, spentInYear: 700.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 31), paceThresholds());

    expect($observations)->toBeEmpty();
});

it('evalua el sobre desde el dia 1, porque el piso de proyeccion protege una proyeccion que aca no existe', function () {
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 1350.0, spentInYear: 1350.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 1, daysInMonth: 31), paceThresholds());

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->band)->toBe('envelope_exceeded');
});

it('no marca lumpy una observacion de sobre, aunque una sola compra sea todo el mes', function () {
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 350.0, spentInYear: 1350.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 31), paceThresholds());

    // La irregularidad no es una anomalia que haya que reportar: es la forma
    // esperada de un sobre.
    expect($observations[0]->isLumpy)->toBeFalse()
        ->and($observations[0]->periodKind)->toBe(BudgetPeriod::YEARLY);
});

it('sigue ignorando un sobre anual en un mes que el usuario no lo toco', function () {
    // El guard de spent > 0 sobrevive: narrar un hecho sin causa de este mes
    // rompe "el hecho y su causa" (ADR-0009).
    $snapshot = envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 0.0, spentInYear: 1350.0);

    $observations = (new PaceEvaluator)->evaluate([$snapshot], paceCursor(day: 15, daysInMonth: 31), paceThresholds());

    expect($observations)->toBeEmpty();
});

it('ordena un sobre excedido junto a un over_budget, y ambos por encima de lo que solo se proyecta', function () {
    $observations = (new PaceEvaluator)->evaluate([
        paceSnapshot(categoryId: 1, name: 'Comida', monthlyBudget: 400.0, spent: 340.0),
        envelopeSnapshot(annualBudget: 1200.0, spentThisMonth: 350.0, spentInYear: 1350.0),
        paceSnapshot(categoryId: 3, name: 'Transporte', monthlyBudget: 200.0, spent: 260.0),
    ], paceCursor(day: 12, daysInMonth: 31), paceThresholds());

    expect($observations)->toHaveCount(3)
        ->and(array_map(fn ($o) => $o->band, $observations))
        ->toBe(['over_budget', 'envelope_exceeded', 'projected_over']);
});
