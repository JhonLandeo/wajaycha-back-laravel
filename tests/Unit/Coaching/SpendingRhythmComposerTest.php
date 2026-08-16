<?php

declare(strict_types=1);

use App\DTOs\Coaching\SpendingHabit;
use App\Enums\SpendingRhythm;
use App\Services\Coaching\SpendingRhythmComposer;

function habit(string $name, float $average, SpendingRhythm $rhythm, int $history = 6): SpendingHabit
{
    return new SpendingHabit(
        categoryId: 1,
        name: $name,
        monthlyAverage: $average,
        variation: $rhythm === SpendingRhythm::FIXED ? 0.05 : 0.9,
        monthsOfHistory: $history,
        rhythm: $rhythm,
    );
}

it('encabeza con el mes tipico y reparte en dos', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Alquiler', 1200.0, SpendingRhythm::FIXED),
        habit('Delivery', 800.0, SpendingRhythm::DISCRETIONARY),
    ], 6, 5);

    expect($message)
        ->toContain('los últimos 6 meses completos')
        ->toContain('gastás en promedio S/ 2,000.00 por mes')
        ->toContain('Llega solo — S/ 1,200.00, el 60%')
        ->toContain('Lo decidís vos — S/ 800.00, el 40%');
});

it('nombra las categorias de cada lado', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Alquiler', 1200.0, SpendingRhythm::FIXED),
        habit('Delivery', 800.0, SpendingRhythm::DISCRETIONARY),
    ], 6, 5);

    expect($message)
        ->toContain('• Alquiler: S/ 1,200.00 por mes, casi siempre igual.')
        ->toContain('• Delivery: S/ 800.00 por mes, cambia mes a mes.');
});

/**
 * A split that quietly leaves part of the month out reads as covering all of it.
 * The third state is named rather than dropped, which is the entire reason it
 * exists instead of a boolean.
 */
it('nombra lo que todavia no puede clasificar en vez de omitirlo', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Alquiler', 1200.0, SpendingRhythm::FIXED),
        habit('Salud', 200.0, SpendingRhythm::TOO_NEW, history: 2),
    ], 6, 5);

    expect($message)
        ->toContain('Todavía no puedo clasificar')
        ->toContain('• Salud: S/ 200.00 por mes, 2 meses de historia.');
});

/**
 * The unclassifiable bucket stays out of the totals and out of the percentages.
 * Folding it into either would put a guess inside the one figure this answer is
 * built to deliver.
 */
it('lo no clasificado no entra en el reparto ni en los porcentajes', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Alquiler', 1200.0, SpendingRhythm::FIXED),
        habit('Salud', 800.0, SpendingRhythm::TOO_NEW, history: 1),
    ], 6, 5);

    expect($message)->toContain('gastás en promedio S/ 1,200.00 por mes')
        ->toContain('el 100%');
});

it('contesta algo cuando no hay nada que mirar', function () {
    $message = (new SpendingRhythmComposer)->compose([], 6, 5);

    expect($message)->toBeString()->not->toBe('')
        ->and($message)->toContain('No tengo gastos en los últimos 6 meses');
});

it('contesta algo cuando todo es demasiado nuevo', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Salud', 200.0, SpendingRhythm::TOO_NEW, history: 1),
    ], 6, 5);

    expect($message)->toContain('todavía no tengo suficiente historia')
        ->not->toContain('Llega solo');
});

/**
 * A category with no spend across the whole window averages zero. True, and
 * nothing — a line reading "S/ 0.00 por mes" is only clutter.
 */
it('descarta las categorias que promedian cero', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Alquiler', 1200.0, SpendingRhythm::FIXED),
        habit('Muerta', 0.0, SpendingRhythm::FIXED),
    ], 6, 5);

    expect($message)->not->toContain('Muerta');
});

it('corta las listas en el tope sin tocar los totales', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('A', 400.0, SpendingRhythm::FIXED),
        habit('B', 300.0, SpendingRhythm::FIXED),
        habit('C', 200.0, SpendingRhythm::FIXED),
    ], 6, 2);

    expect($message)->toContain('S/ 900.00')
        ->toContain('• A:')
        ->toContain('• B:')
        ->not->toContain('• C:');
});

it('singulariza un mes de historia', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Alquiler', 1200.0, SpendingRhythm::FIXED),
        habit('Salud', 200.0, SpendingRhythm::TOO_NEW, history: 1),
    ], 6, 5);

    expect($message)->toContain('1 mes de historia')->not->toContain('1 meses');
});

/** design.md D10: sin `parse_mode`, y los nombres de categoría los escribe el usuario. */
it('no emite marcadores de markdown ni html', function () {
    $message = (new SpendingRhythmComposer)->compose([
        habit('Alquiler *nuevo*', 1200.0, SpendingRhythm::FIXED),
    ], 6, 5);

    expect($message)->toContain('• Alquiler')
        ->and(preg_match('/^[-*_#]/m', $message))->toBe(0);
});
