<?php

declare(strict_types=1);

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\Enums\Unit;
use App\Services\Shopping\ShoppingNeedsMerger;

it('sums the same product across sources and keeps one line', function () {
    $merged = (new ShoppingNeedsMerger)->merge(
        [new ConsumptionHabitLine(1, 'Arroz', Unit::Kg, 0.5)],
        [
            new ConsumptionHabitLine(1, 'Arroz', Unit::Kg, 1.25),
            new ConsumptionHabitLine(2, 'Pollo', Unit::Kg, 2.0),
        ],
    );

    expect($merged)->toHaveCount(2)
        ->and($merged[0]->productName)->toBe('Arroz')
        ->and($merged[0]->weeklyQuantity)->toBe(1.75)
        ->and($merged[1]->productName)->toBe('Pollo')
        ->and($merged[1]->weeklyQuantity)->toBe(2.0);
});

it('returns an empty list when there are no sources', function () {
    expect((new ShoppingNeedsMerger)->merge())->toBe([]);
});

it('sorts the merged lines by product name', function () {
    $merged = (new ShoppingNeedsMerger)->merge(
        [new ConsumptionHabitLine(2, 'Papa', Unit::Kg, 1.0)],
        [new ConsumptionHabitLine(1, 'Aceite', Unit::L, 0.5)],
    );

    expect(array_map(static fn (ConsumptionHabitLine $line): string => $line->productName, $merged))
        ->toBe(['Aceite', 'Papa']);
});
