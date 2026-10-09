<?php

declare(strict_types=1);

use App\Services\Prices\CostCalculator;

/**
 * Integer-cent arithmetic (design D16). The cases are the ones PHP's `round()`
 * on a float gets wrong or fragile: 3.995 is not representable, so a float
 * product can land on 3.99 instead of 4.00.
 */
it('rounds half up to cents on the proportional product', function (float $toBuy, string $unitPrice, int $cents) {
    expect((new CostCalculator)->lineCostCents($toBuy, $unitPrice))->toBe($cents);
})->with([
    'half-cent rounds up (0.5 x 7.99 = 3.995)' => [0.5, '7.9900', 400],
    'not pack-rounded (0.1 x 13.50)' => [0.1, '13.5000', 135],
    'no precision loss (3 x 3.90/0.9)' => [3.0, '4.3333', 1300],
    'plain product (1.5 x 8.90)' => [1.5, '8.9000', 1335],
    'just under the half stays down (1 x 0.0049 ~ 0.4 cents)' => [1.0, '0.0049', 0],
    'exactly the half goes up (1 x 0.0050)' => [1.0, '0.0050', 1],
    'three-decimal quantity (0.333 x 3.00)' => [0.333, '3.0000', 100],
]);

it('accepts unit prices as the database hands them over, with any number of trailing zeros', function (string $unitPrice) {
    expect((new CostCalculator)->lineCostCents(2.0, $unitPrice))->toBe(1780);
})->with(['8.9000', '8.90', '8.9']);

it('rounds a price carrying more than four decimals half up at the fourth', function () {
    // 4.33335 -> 4.3334 at four decimals; 3 x 4.3334 = 13.0002 -> 1300 cents.
    expect((new CostCalculator)->lineCostCents(3.0, '4.33335'))->toBe(1300)
        // 0.00005 -> 0.0001 ; 1 x 0.0001 = 0.0001 -> 0 cents
        ->and((new CostCalculator)->lineCostCents(1.0, '0.00005'))->toBe(0);
});

it('rejects a malformed or negative unit price instead of guessing', function (string $bad) {
    expect(fn () => (new CostCalculator)->lineCostCents(1.0, $bad))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '-1.00', '1,50', '1.2.3']);

it('converts cents to a decimal amount and floats to cents without drifting', function () {
    $calc = new CostCalculator;

    expect($calc->centsToAmount(1335))->toBe(13.35)
        ->and($calc->centsToAmount(0))->toBe(0.0)
        ->and($calc->centsToAmount(-1000))->toBe(-10.0)
        // 0.1 + 0.2 style float noise must not leak into cents.
        ->and($calc->amountToCents(100.0))->toBe(10000)
        ->and($calc->amountToCents(19.99))->toBe(1999)
        ->and($calc->amountToCents(-10.0))->toBe(-1000);
});

it('rounds a stored unit price to two decimals for output, half up', function (string $unitPrice, int $cents) {
    expect((new CostCalculator)->unitPriceToCents($unitPrice))->toBe($cents);
})->with([
    'plain' => ['8.9000', 890],
    'four decimals rounded down' => ['4.3333', 433],
    'half-cent rounds up' => ['7.9950', 800],
    'just under the half' => ['7.9949', 799],
    'sub-cent price' => ['0.0049', 0],
]);
