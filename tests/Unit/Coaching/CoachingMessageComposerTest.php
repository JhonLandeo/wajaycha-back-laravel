<?php

declare(strict_types=1);

use App\DTOs\Coaching\BlindnessObservation;
use App\DTOs\Coaching\CoachingCause;
use App\DTOs\Coaching\PaceObservation;
use App\Services\Coaching\CoachingMessageComposer;
use Carbon\CarbonImmutable;

/**
 * CoachingMessageComposer is pure PHP (design.md §6): values in, Spanish strings out.
 * No advice, no suggestion, no next step — only the fact and its cause.
 */
function paceObservation(
    string $subjectKey = 'category:1',
    int $categoryId = 1,
    string $name = 'Comida',
    string $band = 'projected_over',
    bool $isLumpy = false,
    float $spent = 340.0,
    float $budget = 400.0,
    ?float $projected = 878.33,
    int $dayOfMonth = 12,
    mixed $cause = null,
): PaceObservation {
    return new PaceObservation(
        subjectKey: $subjectKey,
        categoryId: $categoryId,
        name: $name,
        band: $band,
        isLumpy: $isLumpy,
        spent: $spent,
        budget: $budget,
        projected: $projected,
        dayOfMonth: $dayOfMonth,
        cause: $cause,
    );
}

it('composes the projected_over shape with its merchant cause (design.md canonical example)', function () {
    $observation = paceObservation(
        band: 'projected_over',
        spent: 340.0,
        budget: 400.0,
        projected: 340.0 * 31 / 12,
        dayOfMonth: 12,
        cause: new CoachingCause(merchantName: 'Rappi', amount: 204.0, frequency: 4),
    );

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->toBe(
        'Comida: S/ 340.00 de S/ 400.00 el día 12, a este ritmo cerrás en S/ 878.33. El 60% son 4 compras de Rappi.'
    );
});

it('composes the over_budget linear shape with its merchant cause', function () {
    $observation = paceObservation(
        name: 'Comida',
        band: 'over_budget',
        isLumpy: false,
        spent: 250.0,
        budget: 200.0,
        projected: null,
        dayOfMonth: 15,
        cause: new CoachingCause(merchantName: 'Plaza Vea', amount: 100.0, frequency: 2),
    );

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->toBe(
        'Comida: S/ 250.00 de S/ 200.00 el día 15, ya pasaste el presupuesto. El 40% son 2 compras de Plaza Vea.'
    );
});

it('uses the singular noun for a single-purchase cause', function () {
    $observation = paceObservation(
        band: 'over_budget',
        spent: 250.0,
        budget: 200.0,
        projected: null,
        dayOfMonth: 15,
        cause: new CoachingCause(merchantName: 'Plaza Vea', amount: 250.0, frequency: 1),
    );

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->toContain('1 compra de Plaza Vea')
        ->and($message)->not->toContain('1 compras');
});

it('composes the over_budget lumpy shape naming the single dominant transaction, without a projection', function () {
    $observation = paceObservation(
        name: 'Tecnología',
        band: 'over_budget',
        isLumpy: true,
        spent: 300.0,
        budget: 200.0,
        projected: null,
        dayOfMonth: 15,
        cause: new CoachingCause(
            merchantName: 'Falabella',
            amount: 210.0,
            frequency: 1,
            transactionDate: CarbonImmutable::create(2026, 8, 9),
        ),
    );

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->toBe(
        'Tecnología: S/ 300.00 de S/ 200.00 el día 15, ya pasaste el presupuesto. El 70% es una sola compra: Falabella, S/ 210.00 el 9.'
    )->and($message)->not->toContain('cerrás en');
});

it('omits the share, but keeps naming the merchant, when a refund makes the merchant total exceed spent (linear)', function () {
    $observation = paceObservation(
        band: 'projected_over',
        spent: 340.0,
        budget: 400.0,
        projected: 340.0 * 31 / 12,
        dayOfMonth: 12,
        cause: new CoachingCause(merchantName: 'Reembolsada', amount: 380.0, frequency: 2),
    );

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->toBe(
        'Comida: S/ 340.00 de S/ 400.00 el día 12, a este ritmo cerrás en S/ 878.33. Fueron 2 compras de Reembolsada.'
    )
        ->and($message)->not->toContain('%')
        ->and($message)->not->toContain('El ');
});

it('omits the share, but keeps naming the merchant, when a refund makes the single purchase exceed spent (lumpy)', function () {
    $observation = paceObservation(
        name: 'Tecnología',
        band: 'over_budget',
        isLumpy: true,
        spent: 300.0,
        budget: 200.0,
        projected: null,
        dayOfMonth: 15,
        cause: new CoachingCause(
            merchantName: 'ReembolsoTienda',
            amount: 320.0,
            frequency: 1,
            transactionDate: CarbonImmutable::create(2026, 8, 5),
        ),
    );

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->toBe(
        'Tecnología: S/ 300.00 de S/ 200.00 el día 15, ya pasaste el presupuesto. Fue una sola compra: ReembolsoTienda, S/ 320.00 el 5.'
    )->and($message)->not->toContain('%');
});

it('never fabricates a cause clause when no cause was ever computed', function () {
    $observation = paceObservation(
        band: 'projected_over',
        spent: 340.0,
        budget: 400.0,
        projected: 340.0 * 31 / 12,
        dayOfMonth: 12,
        cause: null,
    );

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->toBe(
        'Comida: S/ 340.00 de S/ 400.00 el día 12, a este ritmo cerrás en S/ 878.33.'
    );
});

it('composes the blind shape naming every unwatchable category, plural', function () {
    $blindness = new BlindnessObservation(categoryNames: ['Salud', 'Mascotas'], totalSpent: 150.5);

    $message = (new CoachingMessageComposer)->composeBlindness($blindness);

    expect($message)->toBe(
        'No puedo mirar 2 categorías sin presupuesto: Salud, Mascotas. Este mes registraste S/ 150.50 ahí.'
    );
});

it('composes the blind shape with singular agreement for exactly one category', function () {
    $blindness = new BlindnessObservation(categoryNames: ['Salud'], totalSpent: 80.0);

    $message = (new CoachingMessageComposer)->composeBlindness($blindness);

    expect($message)->toBe(
        'No puedo mirar 1 categoría sin presupuesto: Salud. Este mes registraste S/ 80.00 ahí.'
    );
});

it('joins several observations plus a trailing blindness message, in the order given, double-newline separated', function () {
    $first = paceObservation(subjectKey: 'category:1', name: 'Comida', band: 'over_budget', spent: 250.0, budget: 200.0, projected: null, dayOfMonth: 15, cause: null);
    $second = paceObservation(subjectKey: 'category:2', name: 'Transporte', band: 'projected_over', spent: 100.0, budget: 150.0, projected: 200.0, dayOfMonth: 10, cause: null);
    $blindness = new BlindnessObservation(categoryNames: ['Salud'], totalSpent: 80.0);

    $message = (new CoachingMessageComposer)->compose([$first, $second], $blindness);

    expect($message)->toBe(implode("\n\n", [
        (new CoachingMessageComposer)->composeObservation($first),
        (new CoachingMessageComposer)->composeObservation($second),
        (new CoachingMessageComposer)->composeBlindness($blindness),
    ]));
});

it('composes with no trailing blindness message when none was ever detected', function () {
    $only = paceObservation(band: 'over_budget', spent: 250.0, budget: 200.0, projected: null, dayOfMonth: 15, cause: null);

    $message = (new CoachingMessageComposer)->compose([$only]);

    expect($message)->not->toContain('No puedo mirar');
});

it('never outputs a parse_mode marker — the payload stays plain text (D10)', function () {
    $observation = paceObservation(cause: new CoachingCause(merchantName: 'Rappi', amount: 204.0, frequency: 4));

    $message = (new CoachingMessageComposer)->composeObservation($observation);

    expect($message)->not->toContain('parse_mode')
        ->and($message)->not->toContain('<')
        ->and($message)->not->toContain('*');
});

it(
    'cannot ever narrate actual_percentage or a Pareto bucket share, because no DTO the composer accepts carries one (D8, structural)',
    function () {
        // Se barre el NAMESPACE entero, no una lista escrita a mano ni las firmas
        // publicas: CoachingCause viaja anidado dentro de PaceObservation::$cause, que
        // esta tipado mixed, asi que derivar de las firmas lo dejaba afuera. Cualquier
        // DTO nuevo en App\\DTOs\\Coaching queda cubierto sin que nadie lo agregue.
        // Ruta relativa a proposito: este es un test unitario y no levanta la app,
        // asi que app_path() no existe aca.
        $archivos = glob(__DIR__.'/../../../app/DTOs/Coaching/*.php');

        expect($archivos)->not->toBeEmpty('No se encontro ningun DTO de Coaching que auditar.');

        foreach ($archivos as $archivo) {
            $class = 'App\\DTOs\\Coaching\\'.basename($archivo, '.php');

            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                expect(str_contains(strtolower($property->getName()), 'percentage'))->toBeFalse(
                    "{$class} expone una propiedad con 'percentage' ({$property->getName()}); D8 prohibe que el peso de asignacion del Pareto tenga por donde llegar al mensaje."
                );
            }
        }
    }
);

it('never renders the literal string actual_percentage in any composed output, as a second, output-level guard (D8)', function () {
    $observations = [
        paceObservation(band: 'projected_over', cause: new CoachingCause(merchantName: 'Rappi', amount: 204.0, frequency: 4)),
        paceObservation(subjectKey: 'category:2', band: 'over_budget', isLumpy: true, spent: 300.0, budget: 200.0, projected: null, cause: new CoachingCause(merchantName: 'Falabella', amount: 210.0, frequency: 1, transactionDate: CarbonImmutable::create(2026, 8, 9))),
    ];
    $blindness = new BlindnessObservation(categoryNames: ['Salud'], totalSpent: 80.0);

    $message = (new CoachingMessageComposer)->compose($observations, $blindness);

    expect($message)->not->toContain('actual_percentage')
        ->and($message)->not->toContain('actual percentage');
});

it('se niega a hablar antes que decir que pasaste el presupuesto cuando no lo pasaste', function () {
    // Un projected_over sin proyeccion caia al texto de la otra banda y afirmaba
    // "ya pasaste el presupuesto" sobre alguien que no se paso. Preferimos romper.
    $roto = paceObservation(band: 'projected_over', spent: 340.0, budget: 400.0, projected: null);

    expect(fn () => (new CoachingMessageComposer)->compose([$roto], null))
        ->toThrow(InvalidArgumentException::class);
});

it('se niega ante una banda que no conoce, en vez de elegir una frase', function () {
    $desconocida = paceObservation(band: 'banda_inventada', spent: 340.0, budget: 400.0, projected: 500.0);

    expect(fn () => (new CoachingMessageComposer)->compose([$desconocida], null))
        ->toThrow(InvalidArgumentException::class);
});

it('omite la fecha entera cuando la compra dominante no la trae', function () {
    $sinFecha = paceObservation(
        band: 'over_budget',
        isLumpy: true,
        spent: 300.0,
        budget: 200.0,
        projected: null,
        cause: new CoachingCause(merchantName: 'Falabella', amount: 210.0, frequency: 1),
    );

    $mensaje = (new CoachingMessageComposer)->compose([$sinFecha], null);

    // Nunca "el ." ni un espacio colgado antes del punto.
    expect($mensaje)->toContain('Falabella')
        ->and($mensaje)->not->toContain('el .')
        ->and($mensaje)->not->toMatch('/\s+\.$/m');
});
