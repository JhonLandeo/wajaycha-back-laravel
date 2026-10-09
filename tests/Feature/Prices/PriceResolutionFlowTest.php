<?php

declare(strict_types=1);

use App\Enums\PriceState;
use App\Enums\Unit;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\CostCalculator;
use App\Services\Prices\PriceResolver;
use App\Services\Prices\PriceSourceRegistry;
use Carbon\CarbonImmutable;

/**
 * The B1 runtime path end to end, against the real test database: a stored
 * observation is read through the registry's policies and cutoffs, resolved by
 * the pure resolver, and costed in cents. Nothing here is mocked — it is the
 * harness the unit tests deliberately are not.
 */
function resolveStored(Product $product, CarbonImmutable $asOf): App\DTOs\Prices\ResolvedPrice
{
    $registry = app(PriceSourceRegistry::class);
    $quotes = app(PriceRepositoryContract::class)->quotesFor([$product->id], $registry->cutoffsFor($asOf));

    return app(PriceResolver::class)->resolve($product->id, Unit::from($product->unit), $quotes, $registry->enabledPolicies(), $asOf);
}

it('resolves a stored Plaza Vea observation and costs it in cents', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    $product = Product::factory()->create(['unit' => 'kg']);
    PriceObservation::factory()->create([
        'product_id' => $product->id, 'source' => 'plazavea', 'unit' => 'kg', 'unit_price' => '8.9000',
        'period_start' => '2026-10-05', 'period_end' => '2026-10-05',
    ]);

    $resolved = resolveStored($product, CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Lima'));

    expect($resolved->state)->toBe(PriceState::Fresh)
        ->and($resolved->chosen->sourceLabel)->toBe('Plaza Vea')
        ->and($resolved->chosen->attribution)->toBe('Precio online de Plaza Vea al 05/10')
        ->and(app(CostCalculator::class)->lineCostCents(1.5, $resolved->chosen->quote->unitPrice))->toBe(1335);
});

it('stops using the source on the next resolution after the kill switch flips', function () {
    $product = Product::factory()->create(['unit' => 'kg']);
    PriceObservation::factory()->create([
        'product_id' => $product->id, 'source' => 'plazavea', 'unit' => 'kg',
        'period_start' => '2026-10-05', 'period_end' => '2026-10-05',
    ]);
    $asOf = CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Lima');

    config(['prices.sources.plazavea.enabled' => true]);
    $on = resolveStored($product, $asOf);

    config(['prices.sources.plazavea.enabled' => false]);
    $off = resolveStored($product, $asOf);

    config(['prices.sources.plazavea.enabled' => true]);
    $reEnabled = resolveStored($product, $asOf);

    expect($on->state)->toBe(PriceState::Fresh)
        ->and($off->state)->toBe(PriceState::Unknown)
        ->and($reEnabled->state)->toBe(PriceState::Fresh)
        // The data never left the table.
        ->and(PriceObservation::query()->count())->toBe(1);
});

it('falls from fresh to stale to unknown as the source ages, with no error', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    $product = Product::factory()->create(['unit' => 'kg']);
    PriceObservation::factory()->create([
        'product_id' => $product->id, 'source' => 'plazavea', 'unit' => 'kg',
        'period_start' => '2026-10-05', 'period_end' => '2026-10-05',
    ]);

    $states = array_map(
        fn (string $day): PriceState => resolveStored($product, CarbonImmutable::parse("{$day} 12:00:00", 'America/Lima'))->state,
        ['2026-10-13', '2026-10-14', '2026-10-26', '2026-10-27'],
    );

    // 8 days -> fresh, 9 -> stale, 21 -> stale, 22 -> expired (dropped by the cutoff too).
    expect($states)->toBe([PriceState::Fresh, PriceState::Stale, PriceState::Stale, PriceState::Unknown]);
});
