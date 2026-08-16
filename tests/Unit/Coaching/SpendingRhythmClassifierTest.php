<?php

declare(strict_types=1);

/**
 * The statistics behind "¿qué es fijo y qué decido yo?".
 *
 * Nothing in the schema records whether a category is a commitment, so this
 * verdict is derived — which makes it the one answer in the menu that can be
 * confidently, plausibly wrong. Every case below is a shape a real budget has.
 */

use App\Enums\SpendingRhythm;
use App\Services\Coaching\SpendingRhythmClassifier;

function habitRow(?int $categoryId, ?string $name, float $total): object
{
    return (object) [
        'category_id' => $categoryId,
        'category_name' => $name,
        'total' => $total,
    ];
}

/**
 * One category's series, as months of repository rows. `null` marks a month the
 * category does not appear in at all — which is what the database returns, and
 * not the same input as a row with zero.
 *
 * @param  array<int, float|null>  $totals  oldest first
 * @return array<int, array<int, object>>
 */
function monthsOf(array $totals, string $name = 'Categoría', int $id = 1): array
{
    return array_map(
        fn (?float $total): array => $total === null ? [] : [habitRow($id, $name, $total)],
        $totals,
    );
}

function classify(array $months, float $threshold = 0.25, int $minMonths = 3): array
{
    return (new SpendingRhythmClassifier)->classify($months, $threshold, $minMonths);
}

it('llama fijo a lo que no se mueve', function () {
    $habits = classify(monthsOf([1200.0, 1200.0, 1200.0, 1200.0, 1200.0, 1200.0], 'Alquiler'));

    expect($habits)->toHaveCount(1)
        ->and($habits[0]->rhythm)->toBe(SpendingRhythm::FIXED)
        ->and($habits[0]->variation)->toBe(0.0)
        ->and($habits[0]->monthlyAverage)->toBe(1200.0);
});

it('tolera un movimiento chico dentro de lo fijo', function () {
    // Una boleta de luz que oscila unos soles sigue siendo una boleta de luz.
    $habits = classify(monthsOf([100.0, 110.0, 95.0, 105.0, 98.0, 102.0], 'Luz'));

    expect($habits[0]->rhythm)->toBe(SpendingRhythm::FIXED);
});

it('llama decidido a lo que salta', function () {
    $habits = classify(monthsOf([50.0, 400.0, 80.0, 600.0, 120.0, 300.0], 'Delivery'));

    expect($habits[0]->rhythm)->toBe(SpendingRhythm::DISCRETIONARY);
});

/**
 * A ratio and not an absolute spread, which is the whole reason the measure is a
 * coefficient of variation. These two series move by the same S/ 30 and are not
 * the same finding at all.
 */
it('mide la variacion contra el tamaño del gasto, no en soles sueltos', function () {
    $alquiler = classify(monthsOf([1200.0, 1230.0, 1200.0, 1230.0, 1200.0, 1230.0], 'Alquiler'));
    $delivery = classify(monthsOf([40.0, 70.0, 40.0, 70.0, 40.0, 70.0], 'Delivery'));

    expect($alquiler[0]->rhythm)->toBe(SpendingRhythm::FIXED)
        ->and($delivery[0]->rhythm)->toBe(SpendingRhythm::DISCRETIONARY);
});

/**
 * The distinction the third state exists for. A rent first paid two months ago
 * looks perfectly steady over those two months, and saying "fijo" would be a
 * verdict about a habit that has not happened yet.
 */
it('no clasifica una categoria que recien arranca', function () {
    $habits = classify(monthsOf([null, null, null, null, 800.0, 800.0], 'Alquiler'));

    expect($habits[0]->rhythm)->toBe(SpendingRhythm::TOO_NEW)
        ->and($habits[0]->monthsOfHistory)->toBe(2);
});

it('clasifica apenas alcanza el minimo de historia', function () {
    $habits = classify(monthsOf([null, null, null, 800.0, 800.0, 800.0], 'Alquiler'));

    expect($habits[0]->monthsOfHistory)->toBe(3)
        ->and($habits[0]->rhythm)->toBe(SpendingRhythm::FIXED);
});

/**
 * The counterpart, and the reason history is measured from the FIRST spend rather
 * than by counting months with spend. A category touched twice in six months has
 * six months of history and is genuinely erratic — counting occurrences would give
 * it two and file it as too new.
 */
it('un gasto esporadico y viejo si se clasifica, y sale decidido', function () {
    $habits = classify(monthsOf([300.0, null, null, 300.0, null, null], 'Regalos'));

    expect($habits[0]->monthsOfHistory)->toBe(6)
        ->and($habits[0]->rhythm)->toBe(SpendingRhythm::DISCRETIONARY);
});

/**
 * The padding is what makes the case above work. Without a zero for the months a
 * category is missing from, two purchases of S/ 300 read as a perfectly fixed
 * S/ 300 bill.
 */
it('cuenta como cero los meses sin gasto', function () {
    $habits = classify(monthsOf([300.0, null, null, 300.0, null, null], 'Regalos'));

    expect($habits[0]->monthlyAverage)->toBe(100.0);
});

/**
 * The zeros BEFORE a category's first spend are a different thing entirely, and
 * counting them was a real bug: a rent started three months into the window came
 * out with three zeros against three payments of S/ 800, a coefficient of
 * variation near 1, and the verdict "lo decidís vos" — about a rent.
 *
 * Leading zeros are not months the user chose not to spend. They are months the
 * category did not exist.
 */
it('no cuenta los meses anteriores a que la categoria existiera', function () {
    $habits = classify(monthsOf([null, null, null, 800.0, 800.0, 800.0], 'Alquiler'));

    expect($habits[0]->monthlyAverage)->toBe(800.0)
        ->and($habits[0]->variation)->toBe(0.0);
});

/**
 * The interior zeros do stay, and that is the other half of the same rule: a month
 * in the middle where nothing was spent is a real decision, and dropping it would
 * turn every occasional expense into a steady bill.
 */
it('si cuenta los meses sin gasto que estan en el medio', function () {
    $sinHueco = classify(monthsOf([300.0, 300.0, 300.0], 'A'));
    $conHueco = classify(monthsOf([300.0, 0.0, 300.0], 'A'));

    expect($sinHueco[0]->rhythm)->toBe(SpendingRhythm::FIXED)
        ->and($conHueco[0]->rhythm)->toBe(SpendingRhythm::DISCRETIONARY);
});

it('ordena por promedio mensual, el mas grande primero', function () {
    $months = [
        [habitRow(1, 'Comida', 400.0), habitRow(2, 'Alquiler', 1200.0)],
        [habitRow(1, 'Comida', 400.0), habitRow(2, 'Alquiler', 1200.0)],
        [habitRow(1, 'Comida', 400.0), habitRow(2, 'Alquiler', 1200.0)],
    ];

    expect(array_map(fn ($h): string => $h->name, classify($months)))
        ->toBe(['Alquiler', 'Comida']);
});

it('nombra el gasto sin categoria y no lo mezcla con una categoria real', function () {
    $months = [
        [habitRow(null, null, 100.0), habitRow(1, 'Comida', 400.0)],
        [habitRow(null, null, 100.0), habitRow(1, 'Comida', 400.0)],
        [habitRow(null, null, 100.0), habitRow(1, 'Comida', 400.0)],
    ];

    $habits = classify($months);

    expect($habits)->toHaveCount(2)
        ->and($habits[1]->name)->toBe('Sin categoría')
        ->and($habits[1]->categoryId)->toBeNull();
});

it('no explota con una ventana vacia', function () {
    expect(classify([]))->toBe([]);
});

it('no explota con meses sin ninguna fila', function () {
    expect(classify([[], [], []]))->toBe([]);
});

/**
 * A single month cannot vary. The population standard deviation reports zero
 * rather than dividing by `n - 1`, and the history guard is what stops that zero
 * from becoming a verdict.
 */
it('con un solo mes no inventa un veredicto', function () {
    $habits = classify(monthsOf([500.0], 'Comida'));

    expect($habits[0]->variation)->toBe(0.0)
        ->and($habits[0]->rhythm)->toBe(SpendingRhythm::TOO_NEW);
});

it('respeta un umbral mas exigente', function () {
    $serie = monthsOf([100.0, 110.0, 95.0, 105.0, 98.0, 102.0], 'Luz');

    expect(classify($serie, threshold: 0.25)[0]->rhythm)->toBe(SpendingRhythm::FIXED)
        ->and(classify($serie, threshold: 0.01)[0]->rhythm)->toBe(SpendingRhythm::DISCRETIONARY);
});
