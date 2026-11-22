<?php

declare(strict_types=1);

/**
 * The heuristics behind "¿qué llega solo cada mes o cada año?".
 *
 * Nothing in the schema records a subscription, so the whole reading is derived
 * from how often a merchant charges and how much that charge moves. Every case
 * below is a shape a real ledger has, and the ones that must stay out — weekly
 * groceries, two purchases months apart, every-other-month payments — matter as
 * much as the ones that must be named (ADR-0051).
 */

use App\Enums\RecurringCadence;
use App\Services\Recurring\RecurringChargeDetector;
use Carbon\CarbonImmutable;

function recurringCharge(string $merchant, float $amount, string $date): object
{
    return (object) [
        'merchant' => $merchant,
        'amount' => $amount,
        'date_operation' => $date,
    ];
}

/**
 * @param  float[]  $amounts
 * @param  string[]  $dates
 * @return array<int, object>
 */
function recurringSeries(string $merchant, array $amounts, array $dates): array
{
    return array_map(
        static fn (int $index): object => recurringCharge($merchant, $amounts[$index], $dates[$index]),
        array_keys($dates),
    );
}

/**
 * @param  array<int, object>  $charges
 * @return array<int, \App\DTOs\Recurring\RecurringCharge>
 */
function detectRecurring(array $charges, string $today = '2026-11-22'): array
{
    return (new RecurringChargeDetector)->detect(
        charges: $charges,
        variationThreshold: 0.20,
        regularShare: 0.75,
        intervalToleranceShare: 0.20,
        today: CarbonImmutable::parse($today, 'America/Lima'),
        timezone: 'America/Lima',
    );
}

it('nombra un cargo mensual de monto fijo', function () {
    $charges = detectRecurring(recurringSeries(
        'NETFLIX',
        [25.90, 25.90, 25.90, 25.90],
        ['2026-08-15', '2026-09-14', '2026-10-14', '2026-11-13'],
    ));

    expect($charges)->toHaveCount(1)
        ->and($charges[0]->merchant)->toBe('NETFLIX')
        ->and($charges[0]->cadence)->toBe(RecurringCadence::MONTHLY)
        ->and($charges[0]->occurrences)->toBe(4)
        ->and($charges[0]->monthlyEquivalent)->toBe(25.90)
        ->and(round($charges[0]->annualEquivalent, 2))->toBe(310.80)
        ->and($charges[0]->lastChargedOn)->toBe('2026-11-13')
        ->and($charges[0]->nextExpectedOn)->toBe('2026-12-13');
});

it('tolera que el monto se mueva un poco', function () {
    $charges = detectRecurring(recurringSeries(
        'SPOTIFY',
        [25.90, 26.50, 25.50, 26.00],
        ['2026-08-15', '2026-09-14', '2026-10-14', '2026-11-13'],
    ));

    expect($charges)->toHaveCount(1)
        ->and($charges[0]->cadence)->toBe(RecurringCadence::MONTHLY);
});

/**
 * The case this reading must never invent: a weekly supermarket run. The dates
 * are perfectly regular and the merchant is the same; only the amount gives it
 * away, and that is exactly the signal the variation test reads.
 */
it('no confunde las compras semanales con una suscripcion', function () {
    $charges = detectRecurring(recurringSeries(
        'WONG',
        [120.00, 300.00, 80.00, 250.00, 190.00],
        ['2026-10-01', '2026-10-08', '2026-10-15', '2026-10-22', '2026-10-29'],
    ), today: '2026-10-30');

    expect($charges)->toBe([]);
});

it('no nombra dos compras iguales separadas por meses', function () {
    $charges = detectRecurring([
        recurringCharge('TIENDA', 300.00, '2026-03-01'),
        recurringCharge('TIENDA', 300.00, '2026-09-01'),
    ]);

    expect($charges)->toBe([]);
});

it('no nombra un pago cada dos meses como mensual', function () {
    $charges = detectRecurring(recurringSeries(
        'SEGURO',
        [90.00, 90.00, 90.00],
        ['2026-06-01', '2026-08-01', '2026-10-01'],
    ));

    expect($charges)->toBe([]);
});

it('exige tres cobros mensuales antes de afirmar el ritmo', function () {
    $charges = detectRecurring(recurringSeries(
        'NETFLIX',
        [25.90, 25.90],
        ['2026-10-01', '2026-11-01'],
    ));

    expect($charges)->toBe([]);
});

it('detecta un cargo anual con solo dos cobros', function () {
    $charges = detectRecurring(recurringSeries(
        'DOMINIO',
        [60.00, 60.00],
        ['2025-11-01', '2026-11-01'],
    ));

    expect($charges)->toHaveCount(1)
        ->and($charges[0]->cadence)->toBe(RecurringCadence::ANNUAL)
        ->and($charges[0]->monthlyEquivalent)->toBe(5.00)
        ->and($charges[0]->annualEquivalent)->toBe(60.00);
});

it('anualiza un cargo semanal', function () {
    $charges = detectRecurring(recurringSeries(
        'GIMNASIO',
        [10.00, 10.00, 10.00, 10.00],
        ['2026-11-01', '2026-11-08', '2026-11-15', '2026-11-22'],
    ));

    expect($charges)->toHaveCount(1)
        ->and($charges[0]->cadence)->toBe(RecurringCadence::WEEKLY)
        ->and($charges[0]->annualEquivalent)->toBe(520.00)
        ->and(round($charges[0]->monthlyEquivalent, 2))->toBe(43.33);
});

it('usa el ultimo monto como costo actual, no el promedio', function () {
    $charges = detectRecurring(recurringSeries(
        'NETFLIX',
        [20.00, 20.00, 20.00, 30.00],
        ['2026-08-15', '2026-09-14', '2026-10-14', '2026-11-13'],
    ));

    expect($charges)->toHaveCount(1)
        ->and($charges[0]->amount)->toBe(30.00)
        ->and($charges[0]->averageAmount)->toBe(22.50)
        ->and($charges[0]->annualEquivalent)->toBe(360.00);
});

it('no atribuye un salto grande de precio a una suscripcion', function () {
    $charges = detectRecurring(recurringSeries(
        'NETFLIX',
        [20.00, 20.00, 20.00, 35.00],
        ['2026-08-15', '2026-09-14', '2026-10-14', '2026-11-13'],
    ));

    expect($charges)->toBe([]);
});

/**
 * The duplicate this reading must not manufacture: a steady amount on irregular
 * dates is a coincidence, not a plan. Two of the three gaps are normal and the
 * third is a skipped month, which is short of the required share.
 */
it('no acepta un monto fijo con fechas irregulares', function () {
    $charges = detectRecurring(recurringSeries(
        'NETFLIX',
        [25.90, 25.90, 25.90, 25.90],
        ['2026-06-01', '2026-07-01', '2026-08-31', '2026-09-30'],
    ), today: '2026-10-15');

    expect($charges)->toBe([]);
});

/**
 * A pattern that stopped is not a subscription the user pays. The window is two
 * years, so a plan cancelled long ago still has all of its charges inside it;
 * only the recency guard tells the two apart.
 */
it('no nombra una suscripcion que dejo de cobrarse hace meses', function () {
    $charges = detectRecurring(recurringSeries(
        'NETFLIX',
        [25.90, 25.90, 25.90, 25.90],
        ['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01'],
    ), today: '2026-11-22');

    expect($charges)->toBe([]);
});

it('junta las variantes de mayusculas de un mismo comercio', function () {
    $charges = detectRecurring([
        recurringCharge('Netflix', 25.90, '2026-08-15'),
        recurringCharge('NETFLIX', 25.90, '2026-09-14'),
        recurringCharge('netflix', 25.90, '2026-10-14'),
        recurringCharge('NETFLIX', 25.90, '2026-11-13'),
    ]);

    expect($charges)->toHaveCount(1)
        ->and($charges[0]->occurrences)->toBe(4);
});

it('ordena por costo anual, de mayor a menor', function () {
    $charges = detectRecurring([
        ...recurringSeries('NETFLIX', [25.00, 25.00, 25.00], ['2026-09-01', '2026-10-01', '2026-11-01']),
        ...recurringSeries('ALQUILER', [1200.00, 1200.00, 1200.00], ['2026-09-01', '2026-10-01', '2026-11-01']),
    ]);

    expect($charges)->toHaveCount(2)
        ->and($charges[0]->merchant)->toBe('ALQUILER')
        ->and($charges[1]->merchant)->toBe('NETFLIX');
});

it('ignora filas sin comercio o con monto no positivo', function () {
    $charges = detectRecurring([
        recurringCharge('', 25.90, '2026-09-01'),
        recurringCharge('NETFLIX', 25.90, '2026-09-01'),
        recurringCharge('NETFLIX', 0.00, '2026-10-01'),
        recurringCharge('NETFLIX', 25.90, '2026-11-01'),
    ]);

    expect($charges)->toBe([]);
});
