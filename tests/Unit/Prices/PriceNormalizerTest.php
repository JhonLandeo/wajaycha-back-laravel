<?php

declare(strict_types=1);

use App\Enums\PriceBasis;
use App\Services\Prices\Normalization\Dimension;
use App\Services\Prices\Normalization\Measure;
use App\Services\Prices\Normalization\PriceNormalizer;

/**
 * The only place a gram becomes a kilogram (design D5). Every case states the
 * price as the source quotes it and the price per the catalogue unit it must
 * become; a quote that cannot become one honestly is rejected, never scaled by
 * guesswork.
 */
function normalize(string $price, Measure $measure, string $target, ?string $kgPerUnit = null, bool $assumeSingle = false): array
{
    $result = (new PriceNormalizer)->normalize($price, $measure, $target, $kgPerUnit, $assumeSingle);

    return [$result->unitPrice, $result->basis, $result->rejection];
}

it('keeps a price quoted per kilogram as it is', function () {
    expect(normalize('8.90', Measure::bulk(Dimension::Mass), 'kg'))->toBe(['8.9000', PriceBasis::Measured, null])
        ->and(normalize('10.29', Measure::bulk(Dimension::Mass), 'kg'))->toBe(['10.2900', PriceBasis::Measured, null]);
});

it('divides a pack by its size, per kilogram and per litre', function () {
    expect(normalize('4.50', Measure::pack('0.750', Dimension::Mass), 'kg'))->toBe(['6.0000', PriceBasis::Measured, null])
        ->and(normalize('3.90', Measure::pack('0.9', Dimension::Volume), 'l'))->toBe(['4.3333', PriceBasis::Measured, null])
        ->and(normalize('9.79', Measure::bulk(Dimension::Volume), 'l'))->toBe(['9.7900', PriceBasis::Measured, null]);
});

it('rounds the fifth decimal half-up', function () {
    // 1 / 0.3 = 3.33333..., 2 / 0.3 = 6.66666...
    expect(normalize('1.00', Measure::pack('0.3', Dimension::Mass), 'kg')[0])->toBe('3.3333')
        ->and(normalize('2.00', Measure::pack('0.3', Dimension::Mass), 'kg')[0])->toBe('6.6667');
});

it('divides a count pack into single units', function () {
    expect(normalize('15.90', Measure::pack('30', Dimension::Count), 'unidad'))->toBe(['0.5300', PriceBasis::Measured, null])
        ->and(normalize('21.90', Measure::pack('6', Dimension::Count), 'unidad'))->toBe(['3.6500', PriceBasis::Measured, null]);
});

it('prices one pack as one unit when the mapping says a pack is a unit', function () {
    expect(normalize('5.80', Measure::pack('0.17', Dimension::Mass), 'unidad', null, true))->toBe(['5.8000', PriceBasis::Measured, null])
        ->and(normalize('4.20', Measure::pack('0.4', Dimension::Mass), 'unidad', '0.4', true)[0])->toBe('4.2000');
});

it('uses the curated kilogram-per-unit and says so when a count product is priced by weight', function () {
    expect(normalize('7.99', Measure::bulk(Dimension::Mass), 'unidad', '0.2'))->toBe(['1.5980', PriceBasis::Equivalence, null])
        ->and(normalize('7.90', Measure::bulk(Dimension::Mass), 'unidad', '0.08'))->toBe(['0.6320', PriceBasis::Equivalence, null])
        ->and(normalize('4.00', Measure::pack('0.5', Dimension::Mass), 'paquete', '0.5')[1])->toBe(PriceBasis::Equivalence);
});

it('does not let assume-single turn a price per kilogram into a price per unit', function () {
    // A choclo sold by the kilo is not "one choclo": it needs the equivalence.
    expect(normalize('6.00', Measure::bulk(Dimension::Mass), 'unidad', '0.3', true))->toBe(['1.8000', PriceBasis::Equivalence, null])
        ->and(normalize('6.00', Measure::bulk(Dimension::Mass), 'unidad', null, true)[2])->toBe('no_equivalence');
});

it('rejects what cannot become the target unit honestly', function () {
    expect(normalize('4.00', Measure::pack('1', Dimension::Volume), 'kg')[2])->toBe('dimension_mismatch')
        ->and(normalize('4.00', Measure::bulk(Dimension::Mass), 'l')[2])->toBe('dimension_mismatch')
        ->and(normalize('4.00', Measure::pack('6', Dimension::Count), 'kg')[2])->toBe('dimension_mismatch')
        ->and(normalize('4.00', Measure::pack('0.5', Dimension::Mass), 'unidad')[2])->toBe('no_equivalence')
        ->and(normalize('4.00', Measure::bulk(Dimension::Mass), 'g')[2])->toBe('unsupported_unit');
});

it('rejects a price that is not a positive amount', function () {
    expect(normalize('0.00', Measure::bulk(Dimension::Mass), 'kg')[2])->toBe('invalid_price')
        ->and(normalize('abc', Measure::bulk(Dimension::Mass), 'kg')[2])->toBe('invalid_price')
        ->and(normalize('4.00', Measure::pack('0', Dimension::Mass), 'kg')[2])->toBe('invalid_quantity');
});
