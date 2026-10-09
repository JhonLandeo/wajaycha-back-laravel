<?php

declare(strict_types=1);

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\BudgetPeriod;
use App\Enums\Unit;
use App\Services\Shopping\ShoppingPlanService;
use Carbon\CarbonImmutable;

/**
 * `ShoppingPlanService`'s rules, asserted against values — the
 * `ParetoReportBuilderTest` mould (design.md D1). No database, no
 * `RefreshDatabase`, no booted application: every input is a plain DTO the
 * service itself is not allowed to import a model for.
 */
function shoppingHabit(int $productId, string $name, float $weeklyQuantity, Unit $unit = Unit::Kg): ConsumptionHabitLine
{
    return new ConsumptionHabitLine($productId, $name, $unit, $weeklyQuantity);
}

function shoppingStock(int $productId, float $quantity, ?Unit $unit = Unit::Kg, ?CarbonImmutable $expiresOn = null): PantryStock
{
    return new PantryStock($productId, $unit, $quantity, $expiresOn);
}

function shoppingWeek(string $asOf = '2026-09-24 12:00:00'): PlanningWeek
{
    return PlanningWeek::forDate(CarbonImmutable::parse($asOf, 'America/Lima'));
}

function shoppingCeiling(
    float $monthlyBudget,
    float $spent,
    BudgetPeriod $budgetPeriod = BudgetPeriod::MONTHLY,
    int $categoryId = 1,
    string $categoryName = 'Supermercado'
): GroceryCeilingInput {
    return new GroceryCeilingInput($categoryId, $categoryName, $monthlyBudget, $budgetPeriod, $spent);
}

// ------------------------------------------------------- habit-minus-pantry

it('nets available pantry quantity against the declared weekly need', function () {
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Arroz', 2.0)],
        pantry: [shoppingStock(1, 0.5)],
        ceiling: null,
        week: shoppingWeek(),
    );

    expect($plan->lines)->toHaveCount(1);
    $line = $plan->lines[0];
    expect($line->neededQuantity)->toBe(2.0)
        ->and($line->availableQuantity)->toBe(0.5)
        ->and($line->toBuyQuantity)->toBe(1.5);
});

it('excludes a product from lines once the habit is fully covered', function () {
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Palta', 1.0)],
        pantry: [shoppingStock(1, 1.5)],
        ceiling: null,
        week: shoppingWeek(),
    );

    expect($plan->lines)->toBe([])
        ->and($plan->covered)->toHaveCount(1);
    expect($plan->covered[0]->neededQuantity)->toBe(1.0)
        ->and($plan->covered[0]->availableQuantity)->toBe(1.5);
});

it('never buys a negative quantity when pantry exceeds the habit', function () {
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Palta', 1.0)],
        pantry: [shoppingStock(1, 5.0)],
        ceiling: null,
        week: shoppingWeek(),
    );

    expect($plan->covered[0]->availableQuantity)->toBe(5.0);
});

it('yields nothing for a pantry item whose product has no declared habit', function () {
    $plan = (new ShoppingPlanService)->plan(
        habits: [],
        pantry: [shoppingStock(99, 3.0)],
        ceiling: null,
        week: shoppingWeek(),
    );

    expect($plan->lines)->toBe([])
        ->and($plan->covered)->toBe([]);
});

// --------------------------------------------------------------- the expiry cut

it('excludes an expired item from availability but keeps it visible as expiredQuantity', function () {
    $week = shoppingWeek('2026-09-24 12:00:00');
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Leche', 1.0)],
        pantry: [shoppingStock(1, 1.0, Unit::Kg, CarbonImmutable::parse('2026-09-20', 'America/Lima'))],
        ceiling: null,
        week: $week,
    );

    // Fully covering stock, but expired -> the product REAPPEARS on the list.
    expect($plan->lines)->toHaveCount(1);
    $line = $plan->lines[0];
    expect($line->availableQuantity)->toBe(0.0)
        ->and($line->expiredQuantity)->toBe(1.0)
        ->and($line->toBuyQuantity)->toBe(1.0);
});

it('counts a stock item expiring exactly on asOf as still available', function () {
    $week = shoppingWeek('2026-09-24 12:00:00');
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Leche', 1.0)],
        pantry: [shoppingStock(1, 1.0, Unit::Kg, $week->asOf)],
        ceiling: null,
        week: $week,
    );

    expect($plan->lines)->toBe([])
        ->and($plan->covered[0]->availableQuantity)->toBe(1.0);
});

it('surfaces the nearest future expiry on the line without hiding it', function () {
    $week = shoppingWeek('2026-09-24 12:00:00');
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Leche', 5.0)],
        pantry: [
            shoppingStock(1, 1.0, Unit::Kg, CarbonImmutable::parse('2026-09-30', 'America/Lima')),
            shoppingStock(1, 1.0, Unit::Kg, CarbonImmutable::parse('2026-09-26', 'America/Lima')),
        ],
        ceiling: null,
        week: $week,
    );

    expect($plan->lines[0]->soonestExpiryOn?->toDateString())->toBe('2026-09-26');
});

// ------------------------------------------------------------- unit mismatch

it('never converts a unit mismatch, including a null unit, and flags it', function () {
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Aceite', 1.0, Unit::L)],
        pantry: [shoppingStock(1, 500.0, Unit::Ml), shoppingStock(1, 1.0, null)],
        ceiling: null,
        week: shoppingWeek(),
    );

    $line = $plan->lines[0];
    expect($line->availableQuantity)->toBe(0.0)
        ->and($line->unmatchedUnitQuantity)->toBe(501.0)
        ->and($line->hasUnitMismatch)->toBeTrue()
        // A mismatch never nets — the full habit quantity is still owed.
        ->and($line->toBuyQuantity)->toBe(1.0);
});

it('nets normally once the unit matches, unaffected by an unrelated mismatch', function () {
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Arroz', 2.0, Unit::Kg)],
        pantry: [shoppingStock(1, 1.0, Unit::Kg), shoppingStock(1, 3.0, Unit::G)],
        ceiling: null,
        week: shoppingWeek(),
    );

    $line = $plan->lines[0];
    expect($line->availableQuantity)->toBe(1.0)
        ->and($line->unmatchedUnitQuantity)->toBe(3.0)
        ->and($line->toBuyQuantity)->toBe(1.0);
});

// --------------------------------------------------------------- three ceiling states

it('reports unlinked when there is no ceiling input', function () {
    $plan = (new ShoppingPlanService)->plan([], [], null, shoppingWeek());

    expect($plan->ceilingState)->toBe('unlinked')
        ->and($plan->ceilingResolution)->toBe('name_not_found')
        ->and($plan->ceiling)->toBeNull();
});

it('reports unbudgeted when the pinned category carries no monthly_budget', function () {
    $plan = (new ShoppingPlanService)->plan([], [], shoppingCeiling(0.0, 0.0), shoppingWeek());

    expect($plan->ceilingState)->toBe('unbudgeted')
        ->and($plan->ceilingResolution)->toBeNull()
        ->and($plan->ceiling)->toBeNull();
});

it('reports set with the amount and spend when the ceiling is budgeted', function () {
    $plan = (new ShoppingPlanService)->plan([], [], shoppingCeiling(300.0, 120.0), shoppingWeek());

    expect($plan->ceilingState)->toBe('set')
        ->and($plan->ceiling)->not->toBeNull()
        ->and($plan->ceiling->amount)->toBe(300.0)
        ->and($plan->ceiling->spent)->toBe(120.0)
        ->and($plan->ceiling->remaining)->toBe(180.0)
        ->and($plan->ceiling->isExceeded)->toBeFalse()
        ->and($plan->ceiling->overspend)->toBe(0.0);
});

it('states the overspend without hiding it when the ceiling is exceeded', function () {
    $plan = (new ShoppingPlanService)->plan([], [], shoppingCeiling(300.0, 450.0), shoppingWeek());

    expect($plan->ceiling->isExceeded)->toBeTrue()
        ->and($plan->ceiling->overspend)->toBe(150.0)
        ->and($plan->ceiling->remaining)->toBe(-150.0);
});

// ------------------------------------- structural guarantee (q6, design.md D5)

it('leaves lines byte-identical whether the ceiling is exceeded or not', function () {
    $habits = [shoppingHabit(1, 'Arroz', 2.0), shoppingHabit(2, 'Palta', 1.0)];
    $pantry = [shoppingStock(1, 0.5), shoppingStock(2, 0.2)];
    $week = shoppingWeek();

    $underBudget = (new ShoppingPlanService)->plan($habits, $pantry, shoppingCeiling(300.0, 50.0), $week);
    $overBudget = (new ShoppingPlanService)->plan($habits, $pantry, shoppingCeiling(300.0, 999.0), $week);
    $noCeiling = (new ShoppingPlanService)->plan($habits, $pantry, null, $week);

    $underLines = array_map(fn ($l) => $l->toArray(), $underBudget->lines);
    $overLines = array_map(fn ($l) => $l->toArray(), $overBudget->lines);
    $noCeilingLines = array_map(fn ($l) => $l->toArray(), $noCeiling->lines);

    expect($overLines)->toBe($underLines)
        ->and($noCeilingLines)->toBe($underLines);
});

// ---------------------------------------------------------- MONTHLY vs YEARLY window

it('reads the ceiling window as the calendar month for a MONTHLY period', function () {
    $week = shoppingWeek('2026-09-24 12:00:00');
    $plan = (new ShoppingPlanService)->plan([], [], shoppingCeiling(300.0, 50.0, BudgetPeriod::MONTHLY), $week);

    expect($plan->ceiling->windowStartsAt->toDateString())->toBe('2026-09-01')
        ->and($plan->ceiling->windowEndsAt->toDateString())->toBe('2026-10-01');
});

it('reads the ceiling window as the calendar year for a YEARLY period, never a twelfth', function () {
    $week = shoppingWeek('2026-09-24 12:00:00');
    $plan = (new ShoppingPlanService)->plan([], [], shoppingCeiling(1200.0, 50.0, BudgetPeriod::YEARLY), $week);

    expect($plan->ceiling->windowStartsAt->toDateString())->toBe('2026-01-01')
        ->and($plan->ceiling->windowEndsAt->toDateString())->toBe('2027-01-01')
        // The envelope amount itself is never scaled down to a monthly slice.
        ->and($plan->ceiling->amount)->toBe(1200.0);
});

// ------------------------------------------------------------------ sorting

it('sorts lines by product name', function () {
    $plan = (new ShoppingPlanService)->plan(
        habits: [shoppingHabit(1, 'Zanahoria', 1.0), shoppingHabit(2, 'Ajo', 1.0)],
        pantry: [],
        ceiling: null,
        week: shoppingWeek(),
    );

    expect(array_map(fn ($l) => $l->productName, $plan->lines))->toBe(['Ajo', 'Zanahoria']);
});
