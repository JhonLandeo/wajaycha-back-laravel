<?php

declare(strict_types=1);

/**
 * The wording of the daily allowance.
 *
 * Asserted as tightly as the arithmetic, because the same figure means different
 * things depending on the sentence around it: "te quedan S/ 550" and "podés gastar
 * S/ 50 por día" are the same budget, and only the second one answers what was
 * asked.
 */

use App\DTOs\Coaching\DailyAllowance;
use App\DTOs\Coaching\MonthCursor;
use App\Services\Coaching\DailyAllowanceComposer;
use Carbon\CarbonImmutable;

function composerCursor(string $date): MonthCursor
{
    return MonthCursor::forInstant(CarbonImmutable::parse($date, 'America/Lima'));
}

function allowance(string $name, float $budget, float $spent, float $perDay): DailyAllowance
{
    return new DailyAllowance(
        categoryId: 1,
        name: $name,
        budget: $budget,
        spent: $spent,
        remaining: $budget - $spent,
        perDay: $perDay,
    );
}

it('encabeza con el total del dia y detalla por categoria', function () {
    $message = (new DailyAllowanceComposer)->compose(
        [allowance('Comida', 800.0, 250.0, 50.0), allowance('Transporte', 300.0, 200.0, 9.09)],
        composerCursor('2026-06-20'),
    );

    expect($message)
        ->toContain('Quedan 11 días de mes')
        ->toContain('Podés gastar hoy S/ 59.09')
        ->toContain('• Comida: S/ 50.00 por día, S/ 550.00 hasta fin de mes.');
});

/**
 * The word carries the one thing the figure leaves out. Yearly envelopes are not
 * in this total, and a reader who cannot see that omission stated will read the
 * number as covering everything they budgeted.
 */
it('dice que el total es de los presupuestos mensuales', function () {
    $message = (new DailyAllowanceComposer)->compose(
        [allowance('Comida', 800.0, 250.0, 50.0)],
        composerCursor('2026-06-20'),
    );

    expect($message)->toContain('mensuales');
});

/**
 * Never null, unlike `BudgetDigestComposer`. The digest speaks unprompted and
 * silence is how it avoids becoming wallpaper; this text exists because someone
 * pressed a button, and a question that gets no answer reads as a broken bot.
 */
it('contesta algo util cuando no hay ningun presupuesto mensual', function () {
    $message = (new DailyAllowanceComposer)->compose([], composerCursor('2026-06-20'));

    expect($message)->toBeString()->not->toBe('')
        ->and($message)->toContain('No tenés presupuestos mensuales');
});

it('no promete margen cuando todo esta pasado', function () {
    $message = (new DailyAllowanceComposer)->compose(
        [allowance('Delivery', 300.0, 340.0, 0.0)],
        composerCursor('2026-06-20'),
    );

    expect($message)
        ->toContain('ya no queda margen')
        ->toContain('• Delivery: S/ 340.00 de S/ 300.00, S/ 40.00 encima.')
        ->not->toContain('Podés gastar hoy');
});

it('separa lo que tiene margen de lo que ya no', function () {
    $message = (new DailyAllowanceComposer)->compose(
        [allowance('Comida', 800.0, 250.0, 50.0), allowance('Delivery', 300.0, 340.0, 0.0)],
        composerCursor('2026-06-20'),
    );

    expect($message)
        ->toContain('Podés gastar hoy S/ 50.00')
        ->toContain('Estos ya no tienen margen:')
        ->toContain('S/ 40.00 encima');
});

/**
 * The overspend is rendered from `abs()`. Without it the line reads
 * "S/ -40.00 encima", which is the figure negated twice by the prose.
 */
it('dice cuanto se paso en positivo', function () {
    $message = (new DailyAllowanceComposer)->compose(
        [allowance('Delivery', 300.0, 340.0, 0.0)],
        composerCursor('2026-06-20'),
    );

    expect($message)->not->toContain('-40');
});

it('singulariza el ultimo dia del mes', function () {
    $message = (new DailyAllowanceComposer)->compose(
        [allowance('Comida', 800.0, 700.0, 100.0)],
        composerCursor('2026-06-30'),
    );

    expect($message)->toContain('Quedan 1 día de mes')->not->toContain('1 días');
});

/**
 * design.md D10: `TelegramChannel::reply()` posts without `parse_mode`, and
 * category names are user-controlled. A leading `-` or `*` would become a marker
 * the day a parse mode is switched on; `•` is a marker in neither syntax.
 */
it('no emite marcadores de markdown ni html', function () {
    $message = (new DailyAllowanceComposer)->compose(
        [allowance('Comida *importante*', 800.0, 250.0, 50.0)],
        composerCursor('2026-06-20'),
    );

    expect($message)->toContain('• Comida')
        ->and(preg_match('/^[-*_#]/m', $message))->toBe(0);
});
