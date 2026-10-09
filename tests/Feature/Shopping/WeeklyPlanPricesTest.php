<?php

declare(strict_types=1);

use App\Enums\Unit;
use App\Models\Category;
use App\Models\ConsumptionHabit;
use App\Models\GroceryBudgetLink;
use App\Models\PantryItem;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\Transaction;
use App\Repositories\Contracts\PriceRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/shopping/weekly-plan with prices (grocery-prices spec, capability
 * `shopping`). The endpoint must never fail, change status or change a line
 * because of price data, and must never reach the network.
 */
beforeEach(function () {
    // Thursday 2026-10-08, midday in Lima: Plaza Vea's 05/10 observation is 3 days old.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Lima'));
});

/**
 * @return array{0: App\Models\User, 1: array<string, string>, 2: Product}
 */
function planUserWithHabit(float $weekly = 1.5, string $name = 'Pollo', Unit $unit = Unit::Kg): array
{
    [$user, $headers] = test()->userWithAuth();
    $product = Product::factory()->create(['name' => $name, 'unit' => $unit->value]);
    ConsumptionHabit::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'weekly_quantity' => $weekly, 'unit' => $unit->value]);

    return [$user, $headers, $product];
}

function plazaVeaRow(Product $product, string $unitPrice = '8.9000', string $day = '2026-10-05'): PriceObservation
{
    return PriceObservation::factory()->create([
        'product_id' => $product->id, 'source' => 'plazavea', 'unit' => $product->unit, 'unit_price' => $unitPrice,
        'period_start' => $day, 'period_end' => $day,
    ]);
}

it('prices a line with its attribution, dates and the proportional cost', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    [, $headers, $product] = planUserWithHabit(1.5);
    plazaVeaRow($product);

    $data = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk()->json('data');

    expect($data['lines'][0]['to_buy_quantity'])->toBe(1.5)
        ->and($data['lines'][0]['price'])->toMatchArray([
            'state' => 'fresh',
            'unit_price' => 8.9,
            // Proportional to the quantity still needed (1.5 x 8.90), not rounded up to whole packs.
            'estimated_cost' => 13.35,
            'basis' => 'measured',
            'source' => 'plazavea',
            'source_label' => 'Plaza Vea',
            'period_start' => '2026-10-05',
            'period_end' => '2026-10-05',
            'attribution' => 'Precio online de Plaza Vea al 05/10',
        ])
        ->and($data['estimate'])->toBe(['total' => 13.35, 'priced_lines' => 1, 'unpriced_lines' => 0, 'is_partial' => false, 'ceiling_comparison' => null]);
});

it('degrades to phase 1 lines and unknown prices when every source is off', function () {
    [, $headers, $product] = planUserWithHabit(1.5);
    plazaVeaRow($product);
    foreach (['plazavea', 'inei', 'emmsa', 'gmml'] as $source) {
        config(["prices.sources.{$source}.enabled" => false]);
    }

    $data = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk()->json('data');

    expect($data['lines'])->toHaveCount(1)
        ->and($data['lines'][0]['to_buy_quantity'])->toBe(1.5)
        ->and($data['lines'][0]['price']['state'])->toBe('unknown')
        ->and($data['lines'][0]['price']['unit_price'])->toBeNull()
        ->and($data['lines'][0]['price']['estimated_cost'])->toBeNull()
        ->and($data['estimate']['total'])->toBeNull()
        ->and($data['estimate']['is_partial'])->toBeTrue()
        // A fresh user's seeded grocery category has no budget: the phase-1 answer.
        ->and($data['ceiling_state'])->toBe('unbudgeted');
});

it('answers 200 with the phase 1 plan and reports to Sentry when the price read throws', function () {
    Exceptions::fake();
    [, $headers] = planUserWithHabit(1.5);
    $this->mock(PriceRepositoryContract::class, function ($mock) {
        $mock->shouldReceive('quotesFor')->andThrow(new RuntimeException('prices are down'));
    });

    $data = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk()->json('data');

    expect($data['lines'])->toHaveCount(1)
        ->and($data['lines'][0]['to_buy_quantity'])->toBe(1.5)
        ->and($data['lines'][0]['price'])->toBeNull()
        ->and($data['lines'][0]['wholesale_trend'])->toBeNull()
        ->and($data['estimate'])->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'prices are down');
});

it('makes no outbound HTTP request while building the plan', function () {
    Http::fake();
    config(['prices.sources.plazavea.enabled' => true]);
    [, $headers, $product] = planUserWithHabit();
    plazaVeaRow($product);

    $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk();

    Http::assertNothingSent();
});

it('does not leak one user\'s private product into another user\'s plan', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    [$owner, $ownerHeaders] = $this->userWithAuth();
    $private = Product::factory()->ownedBy($owner->id)->create(['name' => 'Salsa de la abuela', 'unit' => 'unidad']);
    ConsumptionHabit::factory()->create(['user_id' => $owner->id, 'product_id' => $private->id, 'weekly_quantity' => 1.0, 'unit' => 'unidad']);

    [, $otherHeaders] = $this->userWithAuth();

    $mine = $this->getJson('/api/shopping/weekly-plan', $ownerHeaders)->assertOk()->json('data');
    $theirs = $this->getJson('/api/shopping/weekly-plan', $otherHeaders)->assertOk()->json('data');

    expect($mine['lines'][0]['product_name'])->toBe('Salsa de la abuela')
        // No mapping exists for a user-created product, so it is simply unknown.
        ->and($mine['lines'][0]['price']['state'])->toBe('unknown')
        ->and($theirs['lines'])->toBe([])
        ->and(json_encode($theirs))->not->toContain('Salsa de la abuela');
});

it('gives covered products no price', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    [$user, $headers, $product] = planUserWithHabit(1.0);
    plazaVeaRow($product);
    PantryItem::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2.0, 'unit' => 'kg']);

    $data = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk()->json('data');

    expect($data['lines'])->toBe([])
        ->and($data['covered'])->toHaveCount(1)
        ->and($data['covered'][0])->not->toHaveKey('price')
        ->and($data['estimate']['total'])->toEqual(0);
});

it('shows a wholesale trend without turning it into a price', function () {
    [, $headers, $product] = planUserWithHabit(2.0, 'Papa blanca');
    foreach (['2026-09-30' => '2.0000', '2026-10-07' => '2.2000'] as $day => $price) {
        PriceObservation::factory()->wholesale()->create([
            'product_id' => $product->id, 'unit' => 'kg', 'unit_price' => $price, 'period_start' => $day, 'period_end' => $day,
        ]);
    }

    $data = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk()->json('data');

    // JSON has no float/int distinction: 10.0 round-trips as 10.
    expect($data['lines'][0]['wholesale_trend'])->toEqual(['direction' => 'up', 'change_pct' => 10.0, 'source' => 'emmsa', 'as_of' => '2026-10-07'])
        ->and($data['lines'][0]['price']['state'])->toBe('unknown')
        ->and($data['estimate']['total'])->toBeNull();
});

it('stops using a source on the very next request when it is switched off', function () {
    [, $headers, $product] = planUserWithHabit(1.0);
    plazaVeaRow($product);

    config(['prices.sources.plazavea.enabled' => true]);
    $on = $this->getJson('/api/shopping/weekly-plan', $headers)->json('data.lines.0.price.state');

    config(['prices.sources.plazavea.enabled' => false]);
    $off = $this->getJson('/api/shopping/weekly-plan', $headers)->json('data.lines.0.price.state');

    config(['prices.sources.plazavea.enabled' => true]);
    $back = $this->getJson('/api/shopping/weekly-plan', $headers)->json('data.lines.0.price.state');

    expect([$on, $off, $back])->toBe(['fresh', 'unknown', 'fresh']);
});

it('falls from fresh to stale to unknown as an unrefreshed source ages, never to an error', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    [$user, , $product] = planUserWithHabit(1.0);
    plazaVeaRow($product, day: '2026-10-05');

    $states = [];
    foreach (['2026-10-13', '2026-10-14', '2026-10-26', '2026-10-27'] as $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 12:00:00", 'America/Lima'));
        // A JWT minted on the first day would be long expired by now.
        $response = $this->getJson('/api/shopping/weekly-plan', $this->jwtHeaders($user))->assertOk();
        $states[] = $response->json('data.lines.0.price.state');
    }

    expect($states)->toBe(['fresh', 'stale', 'stale', 'unknown']);
});

it('leaves the ceiling untouched by prices and compares the estimate with what remains', function () {
    [$user, $headers, $product] = planUserWithHabit(2.0, 'Arroz');
    plazaVeaRow($product, '4.5000');
    $category = Category::factory()->create(['user_id' => $user->id, 'monthly_budget' => 100]);
    GroceryBudgetLink::factory()->create(['user_id' => $user->id, 'category_id' => $category->id, 'resolved_by' => 'manual']);
    Transaction::factory()->create([
        'user_id' => $user->id, 'category_id' => $category->id, 'type_transaction' => 'expense', 'amount' => 30.00,
        'date_operation' => CarbonImmutable::now('America/Lima')->startOfMonth()->addDays(2),
    ]);

    config(['prices.sources.plazavea.enabled' => false]);
    $without = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk()->json('data');

    config(['prices.sources.plazavea.enabled' => true]);
    $with = $this->getJson('/api/shopping/weekly-plan', $headers)->assertOk()->json('data');

    expect($with['ceiling'])->toBe($without['ceiling'])
        ->and($with['lines'][0]['to_buy_quantity'])->toBe($without['lines'][0]['to_buy_quantity'])
        ->and($without['estimate']['ceiling_comparison'])->toBeNull()
        // 100 budget - 30 spent = 70 remaining; 2 kg x 4.50 = 9.00 estimated.
        ->and($with['estimate']['ceiling_comparison'])->toEqual(['remaining' => 70, 'remaining_after_estimate' => 61, 'would_exceed' => false]);
});
