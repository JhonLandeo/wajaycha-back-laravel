<?php

declare(strict_types=1);

use App\DTOs\Prices\VtexCandidate;
use App\Enums\PriceBasis;
use App\Services\Prices\Normalization\PriceMedian;
use App\Services\Prices\Parsers\VtexOfferSelector;
use App\Services\Prices\Parsers\VtexSearchParser;

/**
 * Which Plaza Vea offers count as "the price of this product" (spec "Layer 3 -
 * Plaza Vea weekly retail"): availability, the curated name filters, the pack
 * size, then the median. The mapping and the fixtures are the shipped ones, so
 * a regression in either shows up here.
 */
function selectFor(string $slug, string $unit, ?string $fixture = null): App\DTOs\Prices\VtexSelection
{
    // Read the shipped file directly: unit tests do not boot the application.
    $entry = (require dirname(__DIR__, 3).'/config/prices.php')['mapping'][$slug];
    $kgPerUnit = $entry['kg_per_unit'] ?? null;

    return (new VtexOfferSelector(new App\Services\Prices\Normalization\PackSizeParser, new App\Services\Prices\Normalization\PriceNormalizer))
        ->select(
            (new VtexSearchParser)->parse(vtexFixture($fixture ?? $slug)),
            $entry['plazavea'],
            $unit,
            $kgPerUnit === null ? null : (string) $kgPerUnit,
        );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function candidate(string $name, string $price, array $overrides = []): VtexCandidate
{
    return new VtexCandidate(...array_merge([
        'name' => $name,
        'itemName' => $name,
        'measurementUnit' => 'un',
        'price' => $price,
        'listPrice' => $price,
        'isAvailable' => true,
        'availableQuantity' => 10,
        'skuId' => '1',
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function plainEntry(): array
{
    return ['must_match' => ['/arroz/iu'], 'must_not_match' => ['/olla|arrocera/iu'], 'assume_single' => false];
}

function selectorOver(array $candidates, array $entry, string $unit, ?string $kgPerUnit = null): App\DTOs\Prices\VtexSelection
{
    return (new VtexOfferSelector(new App\Services\Prices\Normalization\PackSizeParser, new App\Services\Prices\Normalization\PriceNormalizer))
        ->select($candidates, $entry, $unit, $kgPerUnit);
}

// ----------------------------------------------------------- median

it('takes the middle value, and the mean of the two middle ones when even', function () {
    expect(PriceMedian::of(['4.0000', '6.0000', '20.0000']))->toBe('6.0000')
        ->and(PriceMedian::of(['20.0000', '4.0000', '6.0000']))->toBe('6.0000')
        ->and(PriceMedian::of(['4.0000', '6.5000']))->toBe('5.2500')
        ->and(PriceMedian::of(['1.1111', '1.1112']))->toBe('1.1112')
        ->and(PriceMedian::of(['8.9000']))->toBe('8.9000');
});

it('has no median of nothing', function () {
    expect(fn () => PriceMedian::of([]))->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------------- per kg and pack sizes

it('uses an item priced per kilo directly and keeps a menudencia chicken', function () {
    $selection = selectFor('pollo-entero', 'kg');

    // "Fresco con Menudencia x kg" 8.90 is the only available whole chicken; the
    // Sabor Lena one is excluded and the PERDIX/SAN FERNANDO ones are out of stock.
    expect($selection->unitPrice)->toBe('8.9000')
        ->and($selection->accepted)->toBe(1)
        ->and($selection->basis)->toBe(PriceBasis::Measured)
        ->and($selection->rejections)->toEqual(['excluded' => 1, 'unavailable' => 4]);
});

it('divides a pack by the size in its name and takes the median of the accepted offers', function () {
    $selection = selectorOver([
        candidate('Arroz Extra COSTEÑO Bolsa 750g', '4.50'),
        candidate('Arroz Superior BELL\'S Bolsa 5Kg', '16.90'),
        candidate('Arroz Extra FARAON Bolsa 750g', '3.50'),
    ], plainEntry(), 'kg');

    // 4.50/0.75 = 6.00, 16.90/5 = 3.38, 3.50/0.75 = 4.6667
    expect($selection->unitPrice)->toBe('4.6667')
        ->and($selection->accepted)->toBe(3)
        ->and($selection->priceMin)->toBe('3.3800')
        ->and($selection->priceMax)->toBe('6.0000')
        ->and($selection->skuIds)->toBe(['1', '1', '1']);
});

it('reports the median of the list prices as the reference price', function () {
    $selection = selectorOver([
        candidate('Arroz Extra COSTEÑO Bolsa 750g', '4.50', ['listPrice' => '6.00']),
        candidate('Arroz Extra FARAON Bolsa 750g', '3.50', ['listPrice' => '4.50']),
        candidate('Arroz Extra BELL\'S Bolsa 750g', '3.00', ['listPrice' => '0.00']),
    ], plainEntry(), 'kg');

    // list/0.75: 8.00 and 6.00; the third has no list price and is left out.
    expect($selection->referencePrice)->toBe('7.0000');
});

it('rejects the offers that are free, out of stock or not what the mapping asks for', function () {
    $selection = selectorOver([
        candidate('Arroz Extra COSTEÑO Bolsa 750g', '4.50'),
        candidate('Arroz Superior Bolsa 1Kg', '0.00'),
        candidate('Arroz Integral Bolsa 1Kg', '5.00', ['isAvailable' => false, 'availableQuantity' => 0]),
        candidate('Arroz Bolsa 1Kg', '5.00', ['availableQuantity' => 0]),
        candidate('Olla Arrocera Arroz Perfecto OSTER 5 tazas 1kg', '120.00'),
        candidate('Fideos Spaghetti Bolsa 500g', '3.00'),
    ], plainEntry(), 'kg');

    expect($selection->accepted)->toBe(1)
        ->and($selection->unitPrice)->toBe('6.0000')
        ->and($selection->rejections)->toEqual(['no_price' => 1, 'unavailable' => 2, 'excluded' => 1, 'not_matched' => 1]);
});

it('writes no price when nothing is accepted and says why', function () {
    $selection = selectorOver([candidate('Olla Arrocera Arroz OSTER 1kg', '120.00')], plainEntry(), 'kg');

    expect($selection->hasPrice())->toBeFalse()
        ->and($selection->unitPrice)->toBeNull()
        ->and($selection->accepted)->toBe(0)
        ->and($selection->rejections)->toEqual(['excluded' => 1]);

    expect(selectorOver([], plainEntry(), 'kg')->hasPrice())->toBeFalse();
});

it('rejects a volume item for a mass product', function () {
    $entry = ['must_match' => ['/leche/iu'], 'must_not_match' => [], 'assume_single' => false];

    $selection = selectorOver([candidate('Leche Gloria Botella 1L', '4.80')], $entry, 'kg');

    expect($selection->hasPrice())->toBeFalse()->and($selection->rejections)->toEqual(['dimension_mismatch' => 1]);
});

it('rejects an item with no pack size and no per-kilo price', function () {
    $selection = selectorOver([candidate('Arroz Extra COSTEÑO Bolsa', '4.50')], plainEntry(), 'kg');

    expect($selection->hasPrice())->toBeFalse()->and($selection->rejections)->toEqual(['no_pack_size' => 1]);
});

// ----------------------------------------------------- count products

it('prices a palta per unit through the pack count, the pack weight or the per-kilo price', function () {
    $selection = selectFor('palta', 'unidad');

    // x kg 7.99 -> 1.598, Fuerte 10.87 -> 2.174, 500g 8.90 -> 3.56, 3 und 6.79 ->
    // 2.2633; the "Pack 2 und." is excluded and two listings are out of stock.
    // Four values, so the median is the mean of 2.174 and 2.2633.
    expect($selection->accepted)->toBe(4)
        ->and($selection->unitPrice)->toBe('2.2187')
        ->and($selection->priceMin)->toBe('1.5980')
        ->and($selection->priceMax)->toBe('3.5600')
        ->and($selection->basis)->toBe(PriceBasis::Equivalence)
        ->and($selection->rejections)->toEqual(['excluded' => 1, 'unavailable' => 2]);
});

it('divides an egg tray by its count and keeps egg whites out', function () {
    $selection = selectFor('huevos', 'unidad');

    // 13 trays priced per egg, from 0.53 (30 un at 15.90) to 1.4583 (12 un at
    // 17.50); the 7th of 13 is the 15 un tray at 12.90 = 0.86. Three quail
    // trays and the egg whites are excluded; one listing is out of stock.
    expect($selection->unitPrice)->toBe('0.8600')
        ->and($selection->accepted)->toBe(13)
        ->and($selection->priceMin)->toBe('0.5300')
        ->and($selection->priceMax)->toBe('1.4583')
        ->and($selection->basis)->toBe(PriceBasis::Measured)
        ->and($selection->rejections)->toEqual(['excluded' => 4, 'unavailable' => 1]);
});

it('prices evaporated milk per can, dividing the six-pack', function () {
    $selection = selectFor('leche-evaporada', 'unidad');

    // 21.90/6 = 3.65, Danlac bottle 11.99 (one unit), Bell's can 4.00. Median 4.00.
    expect($selection->accepted)->toBe(3)
        ->and($selection->unitPrice)->toBe('4.0000')
        ->and($selection->priceMin)->toBe('3.6500');
});

it('prices bread sold by the kilo per roll through the curated weight', function () {
    $selection = selectFor('pan', 'unidad');

    // 7.90 per kg x 0.08 kg per roll; the second listing is out of stock.
    expect($selection->unitPrice)->toBe('0.6320')
        ->and($selection->basis)->toBe(PriceBasis::Equivalence)
        ->and($selection->accepted)->toBe(1);
});

// ------------------------------------------------------ noise filters

it('keeps gourmet salts and small shakers out of the price of table salt', function () {
    $selection = selectFor('sal', 'kg');

    // Only the 1 kg bags of table, cooking and sea salt: 1.90, 2.60, 1.90.
    expect($selection->accepted)->toBe(3)
        ->and($selection->unitPrice)->toBe('1.9000')
        ->and($selection->priceMax)->toBe('2.6000');
});

it('leaves out gourmet, sticky and bundled rice', function () {
    $selection = selectFor('arroz', 'kg');

    // 20 listings: 8 excluded (integral, arborio, japanese, pack bundles),
    // 12 priced per kilo from 3.30 to 7.4667; the mean of the 6th and 7th
    // (4.10 and 4.30) is the median.
    expect($selection->accepted)->toBe(12)
        ->and($selection->unitPrice)->toBe('4.2000')
        ->and($selection->priceMin)->toBe('3.3000')
        ->and($selection->priceMax)->toBe('7.4667')
        ->and($selection->rejections)->toEqual(['excluded' => 8]);
});
