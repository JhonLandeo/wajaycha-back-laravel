<?php

declare(strict_types=1);

use App\Services\Coaching\MonthOverMonthComparator;

function spendRow(?int $categoryId, ?string $name, float $total): object
{
    return (object) [
        'category_id' => $categoryId,
        'category_name' => $name,
        'total' => $total,
    ];
}

it('resta las dos ventanas por categoria', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(1, 'Delivery', 340.0)],
        [spendRow(1, 'Delivery', 120.0)],
        20.0,
        5,
    );

    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]->name)->toBe('Delivery')
        ->and($shifts[0]->current)->toBe(340.0)
        ->and($shifts[0]->previous)->toBe(120.0)
        ->and($shifts[0]->delta)->toBe(220.0)
        ->and($shifts[0]->rose())->toBeTrue();
});

/**
 * The union of both windows, not just the current one. A category that stopped
 * has no row this month, and stopping is one of the two findings this question
 * exists to surface.
 */
it('encuentra la categoria que desaparecio', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [],
        [spendRow(7, 'Transporte', 150.0)],
        20.0,
        5,
    );

    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]->isGone())->toBeTrue()
        ->and($shifts[0]->delta)->toBe(-150.0);
});

it('encuentra la categoria que aparecio', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(9, 'Delivery', 220.0)],
        [],
        20.0,
        5,
    );

    expect($shifts[0]->isNew())->toBeTrue()
        ->and($shifts[0]->previous)->toBe(0.0);
});

it('descarta los movimientos por debajo del piso', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(1, 'Comida', 415.0)],
        [spendRow(1, 'Comida', 400.0)],
        20.0,
        5,
    );

    expect($shifts)->toBe([]);
});

it('el piso mira la magnitud, no el signo', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(1, 'Comida', 380.0)],
        [spendRow(1, 'Comida', 400.0)],
        15.0,
        5,
    );

    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]->delta)->toBe(-20.0);
});

/**
 * Ranked by magnitude with the sign ignored. Sorting by `delta` alone would push
 * every drop to the bottom even when a drop is the largest movement of the month.
 */
it('ordena por magnitud, suba o baje', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(1, 'Comida', 500.0), spendRow(2, 'Transporte', 40.0), spendRow(3, 'Delivery', 200.0)],
        [spendRow(1, 'Comida', 450.0), spendRow(2, 'Transporte', 340.0), spendRow(3, 'Delivery', 100.0)],
        20.0,
        5,
    );

    expect(array_map(fn ($s): string => $s->name, $shifts))
        ->toBe(['Transporte', 'Delivery', 'Comida']);
});

it('corta en el tope', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(1, 'A', 500.0), spendRow(2, 'B', 400.0), spendRow(3, 'C', 300.0)],
        [],
        20.0,
        2,
    );

    expect($shifts)->toHaveCount(2)
        ->and(array_map(fn ($s): string => $s->name, $shifts))->toBe(['A', 'B']);
});

it('un tope de cero no devuelve nada', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(1, 'A', 500.0)],
        [],
        20.0,
        0,
    );

    expect($shifts)->toBe([]);
});

/**
 * Spending the categoriser has not filed still counts. Dropping it would make the
 * comparison quietly smaller than the ledger, and a hole in categorisation is
 * itself worth seeing.
 */
it('cuenta el gasto sin categoria y lo nombra', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(null, null, 300.0)],
        [spendRow(null, null, 100.0)],
        20.0,
        5,
    );

    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]->categoryId)->toBeNull()
        ->and($shifts[0]->name)->toBe('Sin categoría')
        ->and($shifts[0]->delta)->toBe(200.0);
});

/**
 * `null` cannot be an array key and `0` would collide with a real category id, so
 * the uncategorised bucket travels under `''`. This case is what stops a future
 * `(int) null` from silently merging the two.
 */
it('no mezcla el gasto sin categoria con una categoria real', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [spendRow(null, null, 300.0), spendRow(1, 'Comida', 500.0)],
        [],
        20.0,
        5,
    );

    expect($shifts)->toHaveCount(2)
        ->and(array_map(fn ($s): ?int => $s->categoryId, $shifts))->toBe([1, null]);
});

/**
 * A category renamed between the two windows arrives with a name in one row and
 * possibly none in the other. Whichever window carries a name wins; the id is what
 * identifies the category.
 */
it('toma el nombre de cualquiera de las dos ventanas', function () {
    $shifts = (new MonthOverMonthComparator)->compare(
        [],
        [spendRow(4, 'Salud', 90.0)],
        20.0,
        5,
    );

    expect($shifts[0]->name)->toBe('Salud');
});

it('no devuelve nada cuando las dos ventanas estan vacias', function () {
    expect((new MonthOverMonthComparator)->compare([], [], 20.0, 5))->toBe([]);
});
