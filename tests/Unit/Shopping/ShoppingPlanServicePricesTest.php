<?php

declare(strict_types=1);

use App\DTOs\Prices\PriceContext;
use App\DTOs\Prices\Quote;
use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\DTOs\Shopping\WeeklyShoppingPlan;
use App\Enums\BudgetPeriod;
use App\Enums\PriceBasis;
use App\Enums\Unit;
use App\Services\Shopping\ShoppingPlanService;
use Carbon\CarbonImmutable;
use Tests\Support\PriceFixtures;

/**
 * The price annotations on the weekly plan, asserted against values only.
 * The invariant every test here leans on (spec "Phase-1 guarantees preserved"):
 * prices annotate lines, they never add, remove, filter, reorder or resize one.
 */
function pricedWeek(): PlanningWeek
{
    return PlanningWeek::forDate(CarbonImmutable::parse(PriceFixtures::AS_OF_DAY.' 12:00:00', 'America/Lima'));
}

function pricedHabit(int $productId, string $name, float $weekly, Unit $unit = Unit::Kg): ConsumptionHabitLine
{
    return new ConsumptionHabitLine($productId, $name, $unit, $weekly);
}

/**
 * @param  ConsumptionHabitLine[]  $habits
 * @param  Quote[]|null  $quotes  null = no price context at all (prices disabled or failed)
 * @param  PantryStock[]  $pantry
 */
function pricedPlan(array $habits, ?array $quotes, ?GroceryCeilingInput $ceiling = null, array $pantry = [], string ...$sources): WeeklyShoppingPlan
{
    $context = $quotes === null
        ? null
        : new PriceContext($quotes, PriceFixtures::policies(...($sources === [] ? ['plazavea', 'inei', 'emmsa', 'gmml'] : $sources)));

    return (new ShoppingPlanService)->plan($habits, $pantry, $ceiling, pricedWeek(), $context);
}

/**
 * The quantity facts of a plan, with every price field stripped — what must be
 * identical whether or not prices are present.
 *
 * @return array<string, mixed>
 */
function pricedLinesOnly(WeeklyShoppingPlan $plan): array
{
    $strip = static fn (array $row): array => array_diff_key($row, ['price' => 1, 'wholesale_trend' => 1]);

    return [
        'lines' => array_map(static fn ($line): array => $strip($line->toArray()), $plan->lines),
        'covered' => array_map(static fn ($line): array => $line->toArray(), $plan->covered),
    ];
}

function pricedCeiling(float $remaining, float $spent = 0.0, int $categoryId = 7): GroceryCeilingInput
{
    return new GroceryCeilingInput($categoryId, 'Supermercado', $remaining + $spent, BudgetPeriod::MONTHLY, $spent);
}

// ------------------------------------------------------------- no context

it('keeps the phase-1 shape and adds only null price keys when there is no price context', function () {
    $plan = pricedPlan([pricedHabit(1, 'Arroz', 2.0)], null, pantry: [new PantryStock(1, Unit::Kg, 0.5, null)]);
    $array = $plan->toArray();

    expect(array_keys($array))->toBe(['week', 'lines', 'covered', 'ceiling_state', 'ceiling_resolution', 'ceiling', 'estimate'])
        ->and($array['estimate'])->toBeNull()
        ->and($array['lines'][0])->toHaveKeys(['product_id', 'to_buy_quantity', 'price', 'wholesale_trend'])
        ->and($array['lines'][0]['price'])->toBeNull()
        ->and($array['lines'][0]['wholesale_trend'])->toBeNull()
        ->and($array['lines'][0]['to_buy_quantity'])->toBe(1.5);
});

// ------------------------------------------------- prices never change lines

it('produces identical lines, order and quantities with and without quotes', function () {
    $habits = [pricedHabit(3, 'Tomate', 1.0), pricedHabit(1, 'Arroz', 2.0), pricedHabit(2, 'Papa', 3.0), pricedHabit(4, 'Pan', 1.0, Unit::Unidad)];
    $pantry = [new PantryStock(2, Unit::Kg, 1.0, null), new PantryStock(4, Unit::Unidad, 5.0, null)];
    $quotes = [
        PriceFixtures::quote(1, 'plazavea', '4.0000'),
        PriceFixtures::quote(2, 'plazavea', '2.0000'),
        PriceFixtures::quote(3, 'inei', '5.0000', 20),
        PriceFixtures::quote(4, 'plazavea', '0.6000', 1, 'unidad'),
    ];

    $without = pricedPlan($habits, null, pantry: $pantry);
    $with = pricedPlan($habits, $quotes, pantry: $pantry);

    expect(pricedLinesOnly($with))->toBe(pricedLinesOnly($without))
        ->and(array_map(fn ($l) => $l->productName, $with->lines))->toBe(['Arroz', 'Papa', 'Tomate'])
        // Pan is fully covered: still covered, still carries no price.
        ->and($with->covered)->toHaveCount(1)
        ->and(array_keys($with->covered[0]->toArray()))->not->toContain('price');
});

it('leaves the ceiling untouched by quotes', function () {
    $habits = [pricedHabit(1, 'Arroz', 2.0)];
    $quotes = [PriceFixtures::quote(1, 'plazavea', '4.0000')];
    $ceiling = pricedCeiling(100.0, 30.0);

    $without = pricedPlan($habits, null, $ceiling);
    $with = pricedPlan($habits, $quotes, $ceiling);

    expect($with->ceiling?->toArray())->toBe($without->ceiling?->toArray())
        ->and($with->ceilingState)->toBe($without->ceilingState)
        ->and($with->ceiling?->remaining)->toBe(100.0);
});

// --------------------------------------------------------- per-line price

it('prices a line from a fresh quote with attribution and period', function () {
    $plan = pricedPlan([pricedHabit(1, 'Pollo', 1.5)], [PriceFixtures::quote(1, 'plazavea', '8.9000', 1)]);
    $price = $plan->toArray()['lines'][0]['price'];

    expect($price['state'])->toBe('fresh')
        ->and($price['unit_price'])->toBe(8.9)
        ->and($price['estimated_cost'])->toBe(13.35)
        ->and($price['basis'])->toBe('measured')
        ->and($price['source'])->toBe('plazavea')
        ->and($price['source_label'])->toBe('Plaza Vea')
        ->and($price['period_start'])->toBe('2026-09-23')
        ->and($price['period_end'])->toBe('2026-09-23')
        ->and($price['attribution'])->toBe('Precio online de Plaza Vea al 23/09')
        ->and($price['alternatives'])->toBe([]);
});

it('still prices a line from a stale quote and shows the old date', function () {
    $plan = pricedPlan([pricedHabit(1, 'Pollo', 1.0)], [PriceFixtures::quote(1, 'plazavea', '8.9000', 12)]);
    $price = $plan->toArray()['lines'][0]['price'];

    expect($price['state'])->toBe('stale')
        ->and($price['unit_price'])->toBe(8.9)
        ->and($price['estimated_cost'])->toBe(8.9)
        ->and($price['period_end'])->toBe('2026-09-12');
});

it('marks a line without a usable quote unknown, with nothing that could read as zero', function () {
    $plan = pricedPlan([pricedHabit(1, 'Pollo', 1.0), pricedHabit(2, 'Ajo', 1.0)], [PriceFixtures::quote(1, 'plazavea', '8.9000')]);
    $unknown = $plan->toArray()['lines'][0]['price']; // Ajo sorts first

    expect($unknown)->toBe([
        'unit_price' => null,
        'estimated_cost' => null,
        'state' => 'unknown',
        'basis' => null,
        'source' => null,
        'source_label' => null,
        'period_start' => null,
        'period_end' => null,
        'attribution' => null,
        'alternatives' => [],
    ])->and(json_encode($unknown))->not->toContain(':0');
});

it('leaves a user-created product unknown because no quote exists for it', function () {
    // Product 99 has no mapping, so no source ever produced a row for it.
    $plan = pricedPlan([pricedHabit(99, 'Mi producto', 2.0)], [PriceFixtures::quote(1, 'plazavea', '4.0000')]);

    expect($plan->lines[0]->price?->state->value)->toBe('unknown');
});

it('flags an equivalence-based quote and leaves a measured one measured', function () {
    $habits = [pricedHabit(1, 'Palta', 2.0, Unit::Unidad), pricedHabit(2, 'Arroz', 1.0)];
    $quotes = [
        PriceFixtures::quote(1, 'plazavea', '1.6000', 1, 'unidad', PriceBasis::Equivalence),
        PriceFixtures::quote(2, 'plazavea', '4.0000'),
    ];

    $lines = pricedPlan($habits, $quotes)->toArray()['lines'];

    expect($lines[0]['product_name'])->toBe('Arroz')
        ->and($lines[0]['price']['basis'])->toBe('measured')
        ->and($lines[1]['price']['basis'])->toBe('equivalence');
});

it('ignores a stored unit that differs from the product unit instead of mis-scaling', function () {
    $plan = pricedPlan([pricedHabit(1, 'Palta', 2.0, Unit::Unidad)], [PriceFixtures::quote(1, 'plazavea', '7.9900', 1, 'kg')]);

    expect($plan->lines[0]->price?->state->value)->toBe('unknown')
        ->and($plan->lines[0]->price?->estimatedCost)->toBeNull();
});

it('costs the quantity still needed proportionally, rounding half up', function (float $toBuy, string $unitPrice, float $cost) {
    $plan = pricedPlan([pricedHabit(1, 'X', $toBuy)], [PriceFixtures::quote(1, 'plazavea', $unitPrice)]);

    expect($plan->lines[0]->price?->estimatedCost)->toBe($cost);
})->with([
    'half-cent goes up' => [0.5, '7.9900', 4.0],
    'not pack-rounded' => [0.1, '13.5000', 1.35],
    'no precision loss' => [3.0, '4.3333', 13.0],
    'plain' => [1.5, '8.9000', 13.35],
]);

it('exposes the stored unit price rounded to two decimals and costs from the exact value', function () {
    $price = pricedPlan([pricedHabit(1, 'X', 3.0)], [PriceFixtures::quote(1, 'plazavea', '4.3333')])->toArray()['lines'][0]['price'];

    expect($price['unit_price'])->toBe(4.33)
        ->and($price['estimated_cost'])->toBe(13.0);
});

it('lists the other usable retail quotes as alternatives', function () {
    $quotes = [PriceFixtures::quote(1, 'plazavea', '8.9000', 2), PriceFixtures::quote(1, 'inei', '6.0000', 40)];

    $price = pricedPlan([pricedHabit(1, 'Pollo', 1.0)], $quotes)->toArray()['lines'][0]['price'];

    expect($price['source'])->toBe('plazavea')
        ->and($price['alternatives'])->toHaveCount(1)
        ->and($price['alternatives'][0])->toBe([
            'source' => 'inei',
            'source_label' => 'INEI',
            'unit_price' => 6.0,
            'state' => 'fresh',
            'basis' => 'measured',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'attribution' => 'Promedio Lima INEI, ago 2026',
        ]);
});

// ------------------------------------------------------------- the estimate

it('estimates an empty list as zero and not partial', function () {
    $estimate = pricedPlan([], [PriceFixtures::quote(1, 'plazavea', '4.0000')])->toArray()['estimate'];

    expect($estimate)->toBe(['total' => 0.0, 'priced_lines' => 0, 'unpriced_lines' => 0, 'is_partial' => false, 'ceiling_comparison' => null]);
});

it('leaves the total null, not zero, when lines exist but none is priced', function () {
    $estimate = pricedPlan([pricedHabit(1, 'A', 1.0), pricedHabit(2, 'B', 1.0)], [])->toArray()['estimate'];

    expect($estimate['total'])->toBeNull()
        ->and($estimate['priced_lines'])->toBe(0)
        ->and($estimate['unpriced_lines'])->toBe(2)
        ->and($estimate['is_partial'])->toBeTrue();
});

it('sums every priced line when all are priced', function () {
    $habits = [pricedHabit(1, 'A', 1.0), pricedHabit(2, 'B', 1.0), pricedHabit(3, 'C', 1.0)];
    $quotes = [PriceFixtures::quote(1, 'plazavea', '10.0000'), PriceFixtures::quote(2, 'plazavea', '5.5000'), PriceFixtures::quote(3, 'plazavea', '4.5000')];

    $estimate = pricedPlan($habits, $quotes)->toArray()['estimate'];

    expect($estimate['total'])->toBe(20.0)
        ->and($estimate['priced_lines'])->toBe(3)
        ->and($estimate['unpriced_lines'])->toBe(0)
        ->and($estimate['is_partial'])->toBeFalse();
});

it('excludes unknown lines from the total and counts them', function () {
    $habits = [pricedHabit(1, 'A', 1.0), pricedHabit(2, 'B', 1.0), pricedHabit(3, 'C', 1.0)];
    $quotes = [PriceFixtures::quote(1, 'plazavea', '10.0000'), PriceFixtures::quote(3, 'plazavea', '4.5000')];

    $estimate = pricedPlan($habits, $quotes)->toArray()['estimate'];

    expect($estimate['total'])->toBe(14.5)
        ->and($estimate['priced_lines'])->toBe(2)
        ->and($estimate['unpriced_lines'])->toBe(1)
        ->and($estimate['is_partial'])->toBeTrue();
});

it('counts stale and fresh lines alike in the total', function () {
    $habits = [pricedHabit(1, 'A', 1.0), pricedHabit(2, 'B', 1.0)];
    $quotes = [PriceFixtures::quote(1, 'plazavea', '3.0000', 1), PriceFixtures::quote(2, 'plazavea', '4.0000', 15)];

    expect(pricedPlan($habits, $quotes)->toArray()['estimate']['total'])->toBe(7.0);
});

it('sums in cents so float noise cannot leak into the total', function () {
    $habits = [pricedHabit(1, 'A', 1.0), pricedHabit(2, 'B', 1.0)];
    $quotes = [PriceFixtures::quote(1, 'plazavea', '0.1000'), PriceFixtures::quote(2, 'plazavea', '0.2000')];

    // 0.1 + 0.2 is 0.30000000000000004 in floats.
    expect(pricedPlan($habits, $quotes)->toArray()['estimate']['total'])->toBe(0.3);
});

// ----------------------------------------------------------- ceiling compare

it('compares the estimate with the remaining ceiling', function (float $remaining, float $unitPrice, float $after, bool $exceeds) {
    $plan = pricedPlan(
        [pricedHabit(1, 'A', 1.0)],
        [PriceFixtures::quote(1, 'plazavea', number_format($unitPrice, 4, '.', ''))],
        pricedCeiling($remaining, 25.0),
    );

    expect($plan->toArray()['estimate']['ceiling_comparison'])->toBe([
        'remaining' => $remaining,
        'remaining_after_estimate' => $after,
        'would_exceed' => $exceeds,
    ]);
})->with([
    'fits' => [100.0, 60.0, 40.0, false],
    'exceeds' => [50.0, 60.0, -10.0, true],
    'exactly spent' => [60.0, 60.0, 0.0, false],
]);

it('still returns the comparison when the estimate is partial so the consumer can label it a lower bound', function () {
    $habits = [pricedHabit(1, 'A', 1.0), pricedHabit(2, 'B', 1.0)];
    $estimate = pricedPlan($habits, [PriceFixtures::quote(1, 'plazavea', '60.0000')], pricedCeiling(100.0))->toArray()['estimate'];

    expect($estimate['is_partial'])->toBeTrue()
        ->and($estimate['ceiling_comparison']['remaining_after_estimate'])->toBe(40.0);
});

it('returns no comparison when the ceiling is unlinked or unbudgeted', function () {
    $habits = [pricedHabit(1, 'A', 1.0)];
    $quotes = [PriceFixtures::quote(1, 'plazavea', '5.0000')];
    $unbudgeted = new GroceryCeilingInput(7, 'Supermercado', 0.0, BudgetPeriod::MONTHLY, 0.0);

    $unlinked = pricedPlan($habits, $quotes, null);
    $zeroBudget = pricedPlan($habits, $quotes, $unbudgeted);

    expect($unlinked->ceilingState)->toBe('unlinked')
        ->and($unlinked->toArray()['estimate']['ceiling_comparison'])->toBeNull()
        ->and($zeroBudget->ceilingState)->toBe('unbudgeted')
        ->and($zeroBudget->toArray()['estimate']['ceiling_comparison'])->toBeNull()
        // The estimate itself is still there.
        ->and($zeroBudget->toArray()['estimate']['total'])->toBe(5.0);
});

it('returns no comparison when lines exist but none is priced', function () {
    $plan = pricedPlan([pricedHabit(1, 'A', 1.0)], [], pricedCeiling(100.0));

    expect($plan->ceilingState)->toBe('set')
        ->and($plan->toArray()['estimate']['total'])->toBeNull()
        ->and($plan->toArray()['estimate']['ceiling_comparison'])->toBeNull();
});

it('returns the comparison for an empty list with the remaining untouched', function () {
    $estimate = pricedPlan([], [], pricedCeiling(80.0, 20.0))->toArray()['estimate'];

    expect($estimate['total'])->toBe(0.0)
        ->and($estimate['ceiling_comparison'])->toBe(['remaining' => 80.0, 'remaining_after_estimate' => 80.0, 'would_exceed' => false]);
});

it('keeps listing lines and stacks the estimate on an already overspent ceiling', function () {
    // Budget 100, spent 150: remaining is -50 before the estimate.
    $plan = pricedPlan([pricedHabit(1, 'A', 1.0)], [PriceFixtures::quote(1, 'plazavea', '10.0000')], new GroceryCeilingInput(7, 'Supermercado', 100.0, BudgetPeriod::MONTHLY, 150.0));

    expect($plan->lines)->toHaveCount(1)
        ->and($plan->toArray()['estimate']['ceiling_comparison'])->toBe(['remaining' => -50.0, 'remaining_after_estimate' => -60.0, 'would_exceed' => true]);
});

// ------------------------------------------------------------ wholesale trend

it('attaches a wholesale trend without pricing the line or touching the estimate', function () {
    $trendOnly = [PriceFixtures::quote(1, 'emmsa', '2.0000', 8), PriceFixtures::quote(1, 'emmsa', '2.2000', 1)];

    $plan = pricedPlan([pricedHabit(1, 'Papa', 2.0)], $trendOnly);
    $line = $plan->toArray()['lines'][0];

    expect($line['wholesale_trend'])->toBe(['direction' => 'up', 'change_pct' => 10.0, 'source' => 'emmsa', 'as_of' => '2026-09-23'])
        ->and($line['price']['state'])->toBe('unknown')
        ->and($plan->toArray()['estimate']['total'])->toBeNull()
        ->and($plan->toArray()['estimate']['unpriced_lines'])->toBe(1);
});

it('prices the same with or without wholesale points present', function () {
    $retail = PriceFixtures::quote(1, 'plazavea', '4.0000');
    $trend = [PriceFixtures::quote(1, 'emmsa', '2.0000', 8), PriceFixtures::quote(1, 'emmsa', '2.2000', 1)];

    $plain = pricedPlan([pricedHabit(1, 'Papa', 2.0)], [$retail])->toArray();
    $withTrend = pricedPlan([pricedHabit(1, 'Papa', 2.0)], [$retail, ...$trend])->toArray();

    expect($withTrend['lines'][0]['price'])->toBe($plain['lines'][0]['price'])
        ->and($withTrend['estimate'])->toBe($plain['estimate'])
        ->and($plain['lines'][0]['wholesale_trend'])->toBeNull()
        ->and($withTrend['lines'][0]['wholesale_trend']['direction'])->toBe('up');
});
