<?php

declare(strict_types=1);

use App\DTOs\Prices\PricePolicy;
use App\DTOs\Prices\Quote;
use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Enums\PriceState;
use App\Enums\Unit;
use App\Services\Prices\AttributionFormatter;
use App\Services\Prices\PriceResolver;
use Carbon\CarbonImmutable;

/**
 * `PriceResolver` asserted against values only — no database, no clock, no
 * booted application. `asOf` is always passed in (the plan's reference date),
 * which is what makes the freshness boundaries testable to the day.
 */
const RESOLVER_AS_OF = '2026-10-10 12:00:00';

function resolverAsOf(string $at = RESOLVER_AS_OF): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'America/Lima');
}

function plazaVeaPolicy(int $fresh = 8, int $stale = 21, ?string $attribution = 'Precio online de Plaza Vea al {dd/mm}'): PricePolicy
{
    return new PricePolicy('plazavea', PriceKind::Retail, 'Plaza Vea', 1, $fresh, $stale, $attribution);
}

function ineiPolicy(int $fresh = 75, int $stale = 135): PricePolicy
{
    return new PricePolicy('inei', PriceKind::Retail, 'INEI', 2, $fresh, $stale, 'Promedio Lima INEI, {mmm} {yyyy}');
}

function emmsaPolicy(): PricePolicy
{
    return new PricePolicy('emmsa', PriceKind::Wholesale, 'EMMSA', 10, 4, 4, null);
}

/**
 * @param  PricePolicy[]  $policies
 * @return array<string, PricePolicy>
 */
function resolverPolicies(PricePolicy ...$policies): array
{
    $byKey = [];
    foreach ($policies as $policy) {
        $byKey[$policy->source] = $policy;
    }

    return $byKey;
}

/** A quote whose period ends `$ageDays` before the default as-of day (2026-10-10). */
function resolverQuote(
    string $source,
    int $ageDays,
    string $unitPrice = '8.9000',
    string $unit = 'kg',
    PriceKind $kind = PriceKind::Retail,
    int $productId = 1,
    ?PriceBasis $basis = null,
): Quote {
    $end = CarbonImmutable::parse('2026-10-10')->subDays($ageDays);
    $start = $source === 'inei' ? $end->startOfMonth() : $end;

    return new Quote($productId, $source, $kind, $unit, $unitPrice, $basis ?? PriceBasis::Measured, $start, $end);
}

function resolveFor(array $quotes, array $policies, Unit $unit = Unit::Kg, int $productId = 1, ?CarbonImmutable $asOf = null): App\DTOs\Prices\ResolvedPrice
{
    return (new PriceResolver(new AttributionFormatter))->resolve($productId, $unit, $quotes, $policies, $asOf ?? resolverAsOf());
}

// ------------------------------------------------------------------- ranking

it('picks the best-ranked fresh quote and keeps the other as an alternative', function () {
    $resolved = resolveFor(
        [resolverQuote('inei', 30, '6.0000'), resolverQuote('plazavea', 2, '8.9000')],
        resolverPolicies(plazaVeaPolicy(), ineiPolicy()),
    );

    expect($resolved->state)->toBe(PriceState::Fresh)
        ->and($resolved->chosen->quote->source)->toBe('plazavea')
        ->and($resolved->chosen->quote->unitPrice)->toBe('8.9000')
        ->and($resolved->alternatives)->toHaveCount(1)
        ->and($resolved->alternatives[0]->quote->source)->toBe('inei');
});

it('lets a fresh lower-ranked quote beat a stale higher-ranked one', function () {
    $resolved = resolveFor(
        [resolverQuote('plazavea', 12, '8.9000'), resolverQuote('inei', 30, '6.0000')],
        resolverPolicies(plazaVeaPolicy(), ineiPolicy()),
    );

    expect($resolved->state)->toBe(PriceState::Fresh)
        ->and($resolved->chosen->quote->source)->toBe('inei')
        ->and($resolved->alternatives)->toHaveCount(1)
        ->and($resolved->alternatives[0]->quote->source)->toBe('plazavea')
        ->and($resolved->alternatives[0]->state)->toBe(PriceState::Stale);
});

it('falls back to the best-ranked stale quote when nothing is fresh', function () {
    $resolved = resolveFor(
        [resolverQuote('inei', 100, '6.0000'), resolverQuote('plazavea', 12, '8.9000')],
        resolverPolicies(plazaVeaPolicy(), ineiPolicy()),
    );

    expect($resolved->state)->toBe(PriceState::Stale)
        ->and($resolved->chosen->quote->source)->toBe('plazavea')
        ->and($resolved->alternatives[0]->quote->source)->toBe('inei');
});

it('is unknown when the only quote is expired', function () {
    $resolved = resolveFor([resolverQuote('plazavea', 30)], resolverPolicies(plazaVeaPolicy()));

    expect($resolved->state)->toBe(PriceState::Unknown)
        ->and($resolved->chosen)->toBeNull()
        ->and($resolved->alternatives)->toBe([]);
});

it('never prices a line with a wholesale quote', function () {
    $resolved = resolveFor(
        [resolverQuote('emmsa', 1, '2.2000', 'kg', PriceKind::Wholesale)],
        resolverPolicies(plazaVeaPolicy(), emmsaPolicy()),
    );

    expect($resolved->state)->toBe(PriceState::Unknown)
        ->and($resolved->chosen)->toBeNull();
});

it('ignores a source that is not among the enabled policies, and uses it again once it is', function () {
    $quotes = [resolverQuote('plazavea', 2, '8.9000'), resolverQuote('inei', 30, '6.0000')];

    $disabled = resolveFor($quotes, resolverPolicies(ineiPolicy()));
    $reEnabled = resolveFor($quotes, resolverPolicies(plazaVeaPolicy(), ineiPolicy()));
    $allOff = resolveFor($quotes, []);

    expect($disabled->chosen->quote->source)->toBe('inei')
        ->and($disabled->alternatives)->toBe([])
        ->and($reEnabled->chosen->quote->source)->toBe('plazavea')
        ->and($allOff->state)->toBe(PriceState::Unknown);
});

it('ignores a quote whose unit differs from the line unit', function () {
    $quotes = [resolverQuote('plazavea', 2, '8.9000', 'unidad')];

    $mismatch = resolveFor($quotes, resolverPolicies(plazaVeaPolicy()), Unit::Kg);
    $match = resolveFor($quotes, resolverPolicies(plazaVeaPolicy()), Unit::Unidad);

    expect($mismatch->state)->toBe(PriceState::Unknown)
        ->and($match->state)->toBe(PriceState::Fresh)
        ->and($match->chosen->quote->unit)->toBe('unidad');
});

it('only considers the quotes of the requested product', function () {
    $quotes = [resolverQuote('plazavea', 2, '1.0000', 'kg', PriceKind::Retail, 99), resolverQuote('plazavea', 2, '8.9000', 'kg', PriceKind::Retail, 1)];

    $resolved = resolveFor($quotes, resolverPolicies(plazaVeaPolicy()), Unit::Kg, 1);

    expect($resolved->chosen->quote->unitPrice)->toBe('8.9000')
        ->and($resolved->chosen->quote->productId)->toBe(1)
        ->and($resolved->alternatives)->toBe([]);
});

it('keeps only the newest quote of each source', function () {
    $resolved = resolveFor(
        [resolverQuote('plazavea', 9, '7.0000'), resolverQuote('plazavea', 1, '8.9000')],
        resolverPolicies(plazaVeaPolicy()),
    );

    expect($resolved->chosen->quote->unitPrice)->toBe('8.9000')
        ->and($resolved->state)->toBe(PriceState::Fresh)
        ->and($resolved->alternatives)->toBe([]);
});

// ---------------------------------------------------------------- boundaries

it('classifies Plaza Vea at 8/9/21/22 days and INEI at 75/76/135/136', function (string $source, int $age, PriceState $expected) {
    $policy = $source === 'plazavea' ? plazaVeaPolicy() : ineiPolicy();

    $resolved = resolveFor([resolverQuote($source, $age)], resolverPolicies($policy));

    expect($resolved->state)->toBe($expected);
})->with([
    'plazavea 8d fresh' => ['plazavea', 8, PriceState::Fresh],
    'plazavea 9d stale' => ['plazavea', 9, PriceState::Stale],
    'plazavea 21d stale' => ['plazavea', 21, PriceState::Stale],
    'plazavea 22d expired' => ['plazavea', 22, PriceState::Unknown],
    'inei 75d fresh' => ['inei', 75, PriceState::Fresh],
    'inei 76d stale' => ['inei', 76, PriceState::Stale],
    'inei 135d stale' => ['inei', 135, PriceState::Stale],
    'inei 136d expired' => ['inei', 136, PriceState::Unknown],
]);

it('applies the thresholds it is given, not constants', function () {
    $quotes = [resolverQuote('plazavea', 4)];

    $tight = resolveFor($quotes, resolverPolicies(plazaVeaPolicy(fresh: 3, stale: 6)));
    $loose = resolveFor($quotes, resolverPolicies(plazaVeaPolicy(fresh: 10, stale: 20)));
    $tighter = resolveFor($quotes, resolverPolicies(plazaVeaPolicy(fresh: 1, stale: 3)));

    expect($tight->state)->toBe(PriceState::Stale)
        ->and($loose->state)->toBe(PriceState::Fresh)
        ->and($tighter->state)->toBe(PriceState::Unknown);
});

it('measures age against the Lima date of asOf, not UTC', function () {
    // 2026-10-11 02:00 UTC is still 2026-10-10 21:00 in Lima: age 0, not 1.
    $lateUtc = CarbonImmutable::parse('2026-10-11 02:00:00', 'UTC');

    $resolved = resolveFor([resolverQuote('plazavea', 8)], resolverPolicies(plazaVeaPolicy(fresh: 8, stale: 8)), Unit::Kg, 1, $lateUtc);

    expect($resolved->state)->toBe(PriceState::Fresh);
});

it('treats a quote dated after asOf as fresh', function () {
    $resolved = resolveFor([resolverQuote('inei', -20)], resolverPolicies(ineiPolicy()));

    expect($resolved->state)->toBe(PriceState::Fresh);
});

// ------------------------------------------------ attribution and usability

it('attaches the label and the attribution text to every exposed quote', function () {
    $resolved = resolveFor(
        [resolverQuote('plazavea', 5), resolverQuote('inei', 41)],
        resolverPolicies(plazaVeaPolicy(), ineiPolicy()),
    );

    expect($resolved->chosen->sourceLabel)->toBe('Plaza Vea')
        ->and($resolved->chosen->attribution)->toBe('Precio online de Plaza Vea al 05/10')
        ->and($resolved->alternatives[0]->sourceLabel)->toBe('INEI')
        ->and($resolved->alternatives[0]->attribution)->toBe('Promedio Lima INEI, ago 2026');
});

it('treats a quote without a period or without an attribution template as unusable', function () {
    $undated = new Quote(1, 'plazavea', PriceKind::Retail, 'kg', '8.9000', PriceBasis::Measured, null, null);

    $noPeriod = resolveFor([$undated], resolverPolicies(plazaVeaPolicy()));
    $noTemplate = resolveFor([resolverQuote('plazavea', 2)], resolverPolicies(plazaVeaPolicy(attribution: null)));
    $blankTemplate = resolveFor([resolverQuote('plazavea', 2)], resolverPolicies(plazaVeaPolicy(attribution: '')));
    $control = resolveFor([resolverQuote('plazavea', 2)], resolverPolicies(plazaVeaPolicy()));

    expect($noPeriod->state)->toBe(PriceState::Unknown)
        ->and($noTemplate->state)->toBe(PriceState::Unknown)
        ->and($blankTemplate->state)->toBe(PriceState::Unknown)
        ->and($control->state)->toBe(PriceState::Fresh);
});

it('carries the basis of the chosen quote through', function () {
    $resolved = resolveFor([resolverQuote('plazavea', 2, '1.6000', 'unidad', PriceKind::Retail, 1, PriceBasis::Equivalence)], resolverPolicies(plazaVeaPolicy()), Unit::Unidad);

    expect($resolved->chosen->quote->basis)->toBe(PriceBasis::Equivalence);
});
