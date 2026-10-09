<?php

declare(strict_types=1);

use App\DTOs\Prices\WholesaleTrend;
use App\Services\Prices\WholesaleTrendCalculator;
use Carbon\CarbonImmutable;
use Tests\Support\PriceFixtures;

/**
 * The trend is informational (spec "Wholesale trend annotation"): it compares
 * the latest usable wholesale point with the same source's point about a week
 * earlier, and says nothing whenever that comparison would be unfair.
 */
function trendFor(array $quotes, ?array $policies = null, ?WholesaleTrendCalculator $calculator = null, int $productId = 1): ?WholesaleTrend
{
    return ($calculator ?? new WholesaleTrendCalculator)->compute(
        $productId,
        $quotes,
        $policies ?? PriceFixtures::policies('emmsa', 'gmml', 'plazavea'),
        CarbonImmutable::parse(PriceFixtures::AS_OF_DAY.' 12:00:00', 'America/Lima'),
    );
}

function trendQuote(string $source, string $price, int $age, int $productId = 1): App\DTOs\Prices\Quote
{
    return PriceFixtures::quote($productId, $source, $price, $age);
}

it('reports a rise against the same source a week earlier', function () {
    $trend = trendFor([trendQuote('emmsa', '2.0000', 8), trendQuote('emmsa', '2.2000', 1)]);

    expect($trend)->toBeInstanceOf(WholesaleTrend::class)
        ->and($trend->direction)->toBe('up')
        ->and($trend->changePct)->toBe(10.0)
        ->and($trend->source)->toBe('emmsa')
        ->and($trend->asOf->toDateString())->toBe('2026-09-23');
});

it('reports a fall with a negative percentage', function () {
    $trend = trendFor([trendQuote('emmsa', '2.0000', 8), trendQuote('emmsa', '1.8000', 1)]);

    expect($trend->direction)->toBe('down')
        ->and($trend->changePct)->toBe(-10.0);
});

it('reads a change inside the epsilon as flat and one at the epsilon as a move', function () {
    $within = trendFor([trendQuote('emmsa', '2.0000', 8), trendQuote('emmsa', '2.0400', 1)]);
    $atEpsilon = trendFor([trendQuote('emmsa', '2.0000', 8), trendQuote('emmsa', '2.0600', 1)]);

    expect($within->direction)->toBe('flat')
        ->and($within->changePct)->toBe(2.0)
        ->and($atEpsilon->direction)->toBe('up')
        ->and($atEpsilon->changePct)->toBe(3.0);
});

it('rounds the percentage to one decimal', function () {
    // (2.37 - 2.10) / 2.10 = 12.857...%
    $trend = trendFor([trendQuote('emmsa', '2.1000', 8), trendQuote('emmsa', '2.3700', 1)]);

    expect($trend->changePct)->toBe(12.9);
});

it('says nothing when the source switched between the two points', function () {
    $switched = trendFor([trendQuote('emmsa', '2.0000', 8), trendQuote('gmml', '2.2000', 1)]);
    $sameSource = trendFor([trendQuote('gmml', '2.0000', 8), trendQuote('gmml', '2.2000', 1)]);

    expect($switched)->toBeNull()
        ->and($sameSource)->not->toBeNull()
        ->and($sameSource->source)->toBe('gmml');
});

it('says nothing when a different source sits between the two points', function () {
    $trend = trendFor([trendQuote('emmsa', '2.0000', 8), trendQuote('gmml', '2.1000', 4), trendQuote('emmsa', '2.2000', 1)]);

    expect($trend)->toBeNull();
});

it('says nothing when the latest point is older than four days', function (int $age, bool $expected) {
    $trend = trendFor([trendQuote('emmsa', '2.0000', $age + 7), trendQuote('emmsa', '2.2000', $age)]);

    expect($trend !== null)->toBe($expected);
})->with([
    'age 4 still usable' => [4, true],
    'age 5 too old' => [5, false],
    'age 6 too old' => [6, false],
]);

it('says nothing with a single point or no wholesale point at all', function () {
    expect(trendFor([trendQuote('emmsa', '2.2000', 1)]))->toBeNull()
        ->and(trendFor([]))->toBeNull();
});

it('only accepts a prior point between five and ten days before the latest', function (int $gap, bool $expected) {
    $trend = trendFor([trendQuote('emmsa', '2.0000', 1 + $gap), trendQuote('emmsa', '2.2000', 1)]);

    expect($trend !== null)->toBe($expected);
})->with([
    'gap 4 is too close' => [4, false],
    'gap 5 is the lower edge' => [5, true],
    'gap 7 is ideal' => [7, true],
    'gap 10 is the upper edge' => [10, true],
    'gap 11 is too far' => [11, false],
]);

it('takes the point nearest to seven days before the latest', function () {
    $trend = trendFor([
        trendQuote('emmsa', '1.0000', 1 + 5),  // 5 days earlier: distance 2
        trendQuote('emmsa', '2.0000', 1 + 8),  // 8 days earlier: distance 1  <- nearest
        trendQuote('emmsa', '3.0000', 1 + 10), // 10 days earlier: distance 3
        trendQuote('emmsa', '2.2000', 1),
    ]);

    // Compared against 2.00, not 1.00 or 3.00.
    expect($trend->changePct)->toBe(10.0);
});

it('ignores sources that are not among the enabled policies', function () {
    $quotes = [trendQuote('emmsa', '2.0000', 8), trendQuote('emmsa', '2.2000', 1)];

    expect(trendFor($quotes, PriceFixtures::policies('gmml', 'plazavea')))->toBeNull()
        ->and(trendFor($quotes, PriceFixtures::policies('emmsa')))->not->toBeNull();
});

it('ignores retail quotes and other products', function () {
    $retailOnly = [trendQuote('plazavea', '8.0000', 8), trendQuote('plazavea', '9.0000', 1)];
    $otherProduct = [trendQuote('emmsa', '2.0000', 8, 99), trendQuote('emmsa', '2.2000', 1, 99)];

    expect(trendFor($retailOnly))->toBeNull()
        ->and(trendFor($otherProduct))->toBeNull()
        ->and(trendFor($otherProduct, productId: 99))->not->toBeNull();
});

it('gives up rather than divide by a zero prior price', function () {
    expect(trendFor([trendQuote('emmsa', '0.0000', 8), trendQuote('emmsa', '2.2000', 1)]))->toBeNull();
});

it('applies the thresholds it was built with', function () {
    $quotes = [trendQuote('emmsa', '2.0000', 14), trendQuote('emmsa', '2.2000', 6)];

    $strict = trendFor($quotes);
    $lenient = trendFor($quotes, calculator: new WholesaleTrendCalculator(maxAgeDays: 6, windowMinDays: 5, windowMaxDays: 10, flatEpsilonPct: 20.0));

    expect($strict)->toBeNull()
        ->and($lenient)->not->toBeNull()
        ->and($lenient->direction)->toBe('flat');
});
