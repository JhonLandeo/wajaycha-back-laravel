<?php

declare(strict_types=1);

use App\DTOs\Prices\PricePolicy;
use App\Enums\PriceKind;
use App\Services\Prices\PriceSourceRegistry;
use Carbon\CarbonImmutable;

/**
 * The registry is the read side of the kill switch (design D3): it turns
 * `config/prices.php` into the enabled policies, on every call, so flipping a
 * source off takes effect on the very next request after a config reload —
 * with no cache of its own to go stale.
 */
function priceRegistry(): PriceSourceRegistry
{
    return app(PriceSourceRegistry::class);
}

it('builds a policy for every enabled source from the configuration', function () {
    $policies = priceRegistry()->enabledPolicies();

    // Plaza Vea ships disabled, so it is not here.
    expect(array_keys($policies))->toBe(['inei', 'emmsa', 'gmml']);

    $inei = $policies['inei'];
    expect($inei)->toBeInstanceOf(PricePolicy::class)
        ->and($inei->kind)->toBe(PriceKind::Retail)
        ->and($inei->label)->toBe('INEI')
        ->and($inei->rank)->toBe(2)
        ->and($inei->freshDays)->toBe(75)
        ->and($inei->staleDays)->toBe(135)
        ->and($inei->attribution)->toBe('Promedio Lima INEI, {mmm} {yyyy}')
        ->and($policies['emmsa']->kind)->toBe(PriceKind::Wholesale)
        ->and($policies['emmsa']->attribution)->toBeNull();
});

it('honours the kill switch on the very next call, without a cache to expire', function () {
    $registry = priceRegistry();

    expect($registry->isEnabled('plazavea'))->toBeFalse();

    config(['prices.sources.plazavea.enabled' => true]);

    expect($registry->isEnabled('plazavea'))->toBeTrue()
        ->and($registry->enabledPolicies())->toHaveKey('plazavea')
        ->and($registry->enabledPolicies()['plazavea']->rank)->toBe(1);

    config(['prices.sources.plazavea.enabled' => false, 'prices.sources.inei.enabled' => false]);

    expect($registry->enabledPolicies())->not->toHaveKey('plazavea')
        ->and($registry->enabledPolicies())->not->toHaveKey('inei')
        ->and(array_keys($registry->enabledPolicies()))->toBe(['emmsa', 'gmml']);
});

it('returns no policies when every source is off', function () {
    foreach (['plazavea', 'inei', 'emmsa', 'gmml'] as $key) {
        config(["prices.sources.{$key}.enabled" => false]);
    }

    expect(priceRegistry()->enabledPolicies())->toBe([]);
});

it('knows its source keys and rejects a name it was never given', function () {
    $registry = priceRegistry();

    expect($registry->sourceKeys())->toBe(['plazavea', 'inei', 'emmsa', 'gmml'])
        ->and($registry->knows('plazavea'))->toBeTrue()
        ->and($registry->knows('bogus'))->toBeFalse()
        ->and($registry->isEnabled('bogus'))->toBeFalse();
});

it('derives a read cutoff per enabled source from its stale window', function () {
    config(['prices.sources.plazavea.enabled' => true]);
    $asOf = CarbonImmutable::parse('2026-10-10 12:00:00', 'America/Lima');

    $cutoffs = priceRegistry()->cutoffsFor($asOf);

    // Retail: asOf minus the stale window. Wholesale: the trend needs the
    // latest point (<= 4 days) plus a prior one up to 10 days before it.
    expect($cutoffs['plazavea']->toDateString())->toBe('2026-09-19')
        ->and($cutoffs['inei']->toDateString())->toBe('2026-05-28')
        ->and($cutoffs['emmsa']->toDateString())->toBe('2026-09-26')
        ->and($cutoffs['gmml']->toDateString())->toBe('2026-09-26');

    config(['prices.sources.plazavea.enabled' => false]);

    expect(priceRegistry()->cutoffsFor($asOf))->not->toHaveKey('plazavea');
});
