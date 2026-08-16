<?php

declare(strict_types=1);

use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\SpendShift;
use App\Services\Coaching\MonthOverMonthComposer;
use Carbon\CarbonImmutable;

function shiftCursor(string $date): MonthCursor
{
    return MonthCursor::forInstant(CarbonImmutable::parse($date, 'America/Lima'));
}

function shift(string $name, float $current, float $previous): SpendShift
{
    return new SpendShift(
        categoryId: 1,
        name: $name,
        current: $current,
        previous: $previous,
        delta: $current - $previous,
    );
}

it('encabeza con el movimiento total y el tramo que compara', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [shift('Delivery', 340.0, 120.0)],
        1040.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)
        ->toContain('Comparado con los primeros 16 días del mes pasado')
        ->toContain('gastaste S/ 240.00 más');
});

it('dice cuando se gasto menos', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [shift('Transporte', 90.0, 150.0)],
        700.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('gastaste S/ 100.00 menos')
        ->toContain('Bajó:')
        ->toContain('• Transporte: S/ 90.00 contra S/ 150.00, S/ 60.00 menos.');
});

it('dice cuando el total no se movio', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [],
        800.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('gastaste exactamente lo mismo');
});

/**
 * The headline is the true total movement and the bullets are ranked and capped,
 * so they do not add up to it. That is the Pareto reading working as intended —
 * and it only stays honest because the headline comes from every row rather than
 * from the bullets.
 */
it('el titular no se arma sumando las vinetas', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [shift('Delivery', 340.0, 120.0)],
        1500.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('gastaste S/ 700.00 más')
        ->and($message)->toContain('S/ 220.00 más');
});

/**
 * On 30 March the comparable window closes at 28 February, so this month gets two
 * more days than the one it is measured against. A reader told the spans differ
 * can discount the figure; a reader who is not told cannot.
 */
it('avisa cuando los dos tramos no miden lo mismo', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [],
        900.0,
        700.0,
        shiftCursor('2026-03-30'),
    );

    expect($message)
        ->toContain('los primeros 28 días')
        ->toContain('28 días contra los 30');
});

it('no avisa nada cuando los tramos son iguales', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [],
        900.0,
        700.0,
        shiftCursor('2026-03-15'),
    );

    expect($message)->not->toContain('más corto');
});

/**
 * "S/ 220.00 contra S/ 0.00" is the same arithmetic and the wrong sentence: one
 * of those is not a smaller amount, it is the absence of the habit.
 */
it('le da su propia frase a lo que aparecio', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [shift('Delivery', 220.0, 0.0)],
        1020.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('• Delivery: S/ 220.00, y el mes pasado no gastaste nada acá.')
        ->and($message)->not->toContain('S/ 0.00');
});

it('le da su propia frase a lo que dejo de gastarse', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [shift('Transporte', 0.0, 150.0)],
        650.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('• Transporte: nada este mes, contra S/ 150.00 del mes pasado.');
});

it('separa lo que subio de lo que bajo', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [shift('Delivery', 340.0, 120.0), shift('Transporte', 90.0, 150.0)],
        1000.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('Subió:')->toContain('Bajó:');
});

it('dice que nada se movio en vez de dejar el mensaje colgado', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [],
        810.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('Ninguna categoría se movió');
});

/**
 * Never null, like the rest of the pull answers. Silence to a direct question
 * reads as a broken bot.
 */
it('contesta algo cuando no hay mes anterior con que comparar', function () {
    $message = (new MonthOverMonthComposer)->compose([], 400.0, 0.0, shiftCursor('2026-06-16'));

    expect($message)->toContain('No tengo gastos del mes pasado')
        ->toContain('S/ 400.00');
});

it('contesta algo cuando no hay gastos en ninguno de los dos meses', function () {
    $message = (new MonthOverMonthComposer)->compose([], 0.0, 0.0, shiftCursor('2026-06-16'));

    expect($message)->toBeString()->not->toBe('')
        ->and($message)->toContain('Todavía no tengo gastos');
});

it('singulariza el primer dia del mes', function () {
    $message = (new MonthOverMonthComposer)->compose([], 100.0, 80.0, shiftCursor('2026-06-01'));

    expect($message)->toContain('el primer día del mes pasado');
});

/**
 * design.md D10: no `parse_mode`, and category names are user-controlled.
 */
it('no emite marcadores de markdown ni html', function () {
    $message = (new MonthOverMonthComposer)->compose(
        [shift('Comida *rica*', 340.0, 120.0)],
        1040.0,
        800.0,
        shiftCursor('2026-06-16'),
    );

    expect($message)->toContain('• Comida')
        ->and(preg_match('/^[-*_#]/m', $message))->toBe(0);
});
