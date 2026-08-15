<?php

declare(strict_types=1);

use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceObservation;
use App\Enums\BudgetPeriod;
use App\Services\Coaching\BudgetDigestComposer;
use Carbon\CarbonImmutable;

/**
 * The morning status board. Pure PHP: observations in, one Spanish string out.
 *
 * The digest and the coach answer different questions, and these tests pin the
 * difference. The coach narrates a change once a month; this states the standing
 * position every morning, which is why nothing here touches a ledger.
 */
function digestCursor(int $day = 15, int $month = 8): MonthCursor
{
    return MonthCursor::forInstant(CarbonImmutable::create(2026, $month, $day, 8, 0, 0));
}

function digestObservation(
    string $name = 'Comida',
    string $band = 'over_budget',
    float $spent = 460.0,
    float $budget = 400.0,
    ?float $projected = null,
    BudgetPeriod $periodKind = BudgetPeriod::MONTHLY,
): PaceObservation {
    return new PaceObservation(
        subjectKey: "category:{$name}",
        categoryId: 1,
        name: $name,
        band: $band,
        isLumpy: false,
        spent: $spent,
        budget: $budget,
        projected: $projected,
        dayOfMonth: 15,
        periodKind: $periodKind,
    );
}

it('calla cuando no hay nada pasado ni en camino', function () {
    // Sin esto el parte llega todas las mañanas diga lo que diga, y entonces la
    // mañana que importa se ve igual que las noventa que no.
    expect((new BudgetDigestComposer)->compose([], digestCursor()))->toBeNull();
});

it('lista lo ya pasado con cuanto se paso', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(name: 'Comida', spent: 460.0, budget: 400.0),
        digestObservation(name: 'Transporte', spent: 260.0, budget: 200.0),
    ], digestCursor(day: 15));

    expect($message)->toBe(
        "Presupuestos al día 15.\n\n"
        ."Ya pasaste:\n"
        ."• Comida: S/ 460.00 de S/ 400.00, S/ 60.00 encima.\n"
        .'• Transporte: S/ 260.00 de S/ 200.00, S/ 60.00 encima.'
    );
});

it('pone primero cuanto queda y despues el pronostico', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(name: 'Delivery', band: 'projected_over', spent: 300.0, budget: 400.0, projected: 516.67),
    ], digestCursor());

    // "Te quedan S/ 100.00" es el numero que el lector sostiene contra la
    // proxima compra; la proyeccion es un pronostico y va detras.
    expect($message)->toContain('• Delivery: S/ 300.00 de S/ 400.00, te quedan S/ 100.00 y a este ritmo cerrás en S/ 516.67.');
});

it('no da ordenes, solo cifras', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(name: 'Comida', spent: 460.0, budget: 400.0),
        digestObservation(name: 'Delivery', band: 'projected_over', spent: 300.0, budget: 400.0, projected: 516.67),
    ], digestCursor());

    // ADR-0009 vale igual acá: "te quedan S/ 100.00" es un hecho, "no gastes"
    // es una instruccion y no hay campo por donde una pueda llegar.
    expect($message)->not->toContain('no gastes')
        ->and($message)->not->toContain('deberías')
        ->and($message)->not->toContain('evitá');
});

it('ancla los sobres al año y nunca al dia', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(
            name: 'Salud',
            band: 'envelope_exceeded',
            spent: 1350.0,
            budget: 1200.0,
            periodKind: BudgetPeriod::YEARLY,
        ),
        digestObservation(
            name: 'Seguros',
            band: 'envelope_consumed',
            spent: 960.0,
            budget: 1200.0,
            periodKind: BudgetPeriod::YEARLY,
        ),
    ], digestCursor());

    expect($message)->toContain("Sobres anuales:\n"
        ."• Salud: S/ 1,350.00 de S/ 1,200.00 al año, S/ 150.00 encima.\n"
        .'• Seguros: S/ 960.00 de S/ 1,200.00 al año, te quedan S/ 240.00.');
});

it('no mezcla un sobre anual con lo pasado del mes', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(name: 'Comida', spent: 460.0, budget: 400.0),
        digestObservation(
            name: 'Salud',
            band: 'envelope_exceeded',
            spent: 1350.0,
            budget: 1200.0,
            periodKind: BudgetPeriod::YEARLY,
        ),
    ], digestCursor());

    // Salud excedio su sobre del año, no su presupuesto del mes: ponerlo bajo
    // "Ya pasaste" junto a Comida diria que las dos cifras miden lo mismo.
    $exceededSection = explode("\n\n", $message)[1];
    expect($exceededSection)->toContain('Comida')
        ->and($exceededSection)->not->toContain('Salud');
});

it('omite las secciones que no tienen nada', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(name: 'Delivery', band: 'projected_over', spent: 300.0, budget: 400.0, projected: 516.67),
    ], digestCursor());

    expect($message)->not->toContain('Ya pasaste:')
        ->and($message)->not->toContain('Sobres anuales:')
        ->and($message)->toContain('Vas camino a pasarte:');
});

it('no emite marcadores de markdown ni html', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(name: '*Comida* <b>rica</b>', spent: 460.0, budget: 400.0),
    ], digestCursor());

    // D10: reply() postea sin parse_mode y el nombre lo escribe el usuario. La
    // vineta es "•" justamente porque un "-" o un "*" al inicio de linea se
    // volverian marcado el dia que alguien active un parse mode.
    expect($message)->toContain('• *Comida* <b>rica</b>:')
        ->and($message)->not->toContain('**')
        ->and($message)->not->toContain('__');
});

it('no revienta el parte por una banda de proyeccion sin proyeccion', function () {
    $message = (new BudgetDigestComposer)->compose([
        digestObservation(name: 'Delivery', band: 'projected_over', spent: 300.0, budget: 400.0, projected: null),
    ], digestCursor());

    // El coach lanza una excepcion acá, y hace bien: elegiria una frase falsa.
    // Un parte diario no elige nada — corta antes del pronostico y entrega el
    // dato que importa igual.
    expect($message)->toContain('te quedan S/ 100.00.')
        ->and($message)->not->toContain('cerrás en');
});
