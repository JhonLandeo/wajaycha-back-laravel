<?php

declare(strict_types=1);

/**
 * `config/prices.php` is data, and data needs the same guarantees as code:
 * a typo in a regex or a slug that no longer exists in the catalogue would
 * surface as "no price" in production, which looks exactly like a source
 * being down. These tests turn that into a red build.
 */
const PRICES_SOURCE_KEYS = ['plazavea', 'inei', 'emmsa', 'gmml'];

/**
 * @return string[] every regex configured anywhere in the mapping
 */
function pricesConfiguredPatterns(): array
{
    $patterns = [];

    foreach ((array) config('prices.mapping') as $entry) {
        foreach (['must_match', 'must_not_match'] as $key) {
            foreach ($entry['plazavea'][$key] ?? [] as $pattern) {
                $patterns[] = $pattern;
            }
        }

        if (isset($entry['emmsa']['variety_match'])) {
            $patterns[] = $entry['emmsa']['variety_match'];
        }
    }

    return $patterns;
}

// ------------------------------------------------------------------ sources

it('declares exactly the four sources and defaults Plaza Vea to disabled', function () {
    expect(array_keys((array) config('prices.sources')))->toBe(PRICES_SOURCE_KEYS)
        ->and(config('prices.sources.plazavea.enabled'))->toBeFalse()
        ->and(config('prices.sources.inei.enabled'))->toBeTrue()
        ->and(config('prices.sources.emmsa.enabled'))->toBeTrue()
        ->and(config('prices.sources.gmml.enabled'))->toBeTrue();
});

it('splits the sources into retail and wholesale', function () {
    expect(config('prices.sources.plazavea.kind'))->toBe('retail')
        ->and(config('prices.sources.inei.kind'))->toBe('retail')
        ->and(config('prices.sources.emmsa.kind'))->toBe('wholesale')
        ->and(config('prices.sources.gmml.kind'))->toBe('wholesale');
});

it('ships the staleness windows and the rank the spec promises', function () {
    expect(config('prices.sources.plazavea.fresh_days'))->toBe(8)
        ->and(config('prices.sources.plazavea.stale_days'))->toBe(21)
        ->and(config('prices.sources.inei.fresh_days'))->toBe(75)
        ->and(config('prices.sources.inei.stale_days'))->toBe(135)
        ->and(config('prices.sources.plazavea.rank'))->toBeLessThan(config('prices.sources.inei.rank'))
        ->and(config('prices.trend.max_age_days'))->toBe(4)
        ->and(config('prices.trend.window_min_days'))->toBe(5)
        ->and(config('prices.trend.window_max_days'))->toBe(10)
        ->and(config('prices.trend.flat_epsilon_pct'))->toBe(3);
});

it('gives every retail source an attribution template and the canary thresholds', function () {
    expect(config('prices.sources.plazavea.attribution'))->toBe('Precio online de Plaza Vea al {dd/mm}')
        ->and(config('prices.sources.inei.attribution'))->toBe('Promedio Lima INEI, {mmm} {yyyy}')
        ->and(config('prices.sources.plazavea.canary_min_rows'))->toBe(28)
        ->and(config('prices.sources.inei.canary_min_rows'))->toBe(25)
        ->and(config('prices.sources.emmsa.canary_min_rows'))->toBe(8)
        ->and(config('prices.sources.gmml.canary_min_rows'))->toBe(5)
        ->and(config('prices.sources.inei.max_mom_change'))->toBe(0.6)
        ->and(config('prices.sources.plazavea.spacing_seconds'))->toBeGreaterThanOrEqual(5);
});

// ------------------------------------------------------------------ mapping

it('maps only slugs that exist in the seeded catalogue', function () {
    $catalogue = array_column((array) config('shopping.catalogue'), 'slug');
    $mapped = array_keys((array) config('prices.mapping'));

    expect($mapped)->not->toBeEmpty();

    foreach ($mapped as $slug) {
        expect($catalogue)->toContain($slug);
    }
});

it('compiles every configured pattern', function () {
    $patterns = pricesConfiguredPatterns();

    // Guards the loop: the mapping really does carry patterns to compile.
    expect(count($patterns))->toBeGreaterThan(40);

    foreach ($patterns as $pattern) {
        expect(@preg_match($pattern, ''))->not->toBeFalse("pattern does not compile: {$pattern}");
    }
});

it('matches the name a pattern was written for and rejects the noise it was written against', function () {
    $arroz = config('prices.mapping.arroz.plazavea');

    $matches = fn (array $patterns, string $name): bool => array_reduce(
        $patterns,
        fn (bool $carry, string $p): bool => $carry && preg_match($p, $name) === 1,
        true,
    );
    $hitsAny = fn (array $patterns, string $name): bool => array_reduce(
        $patterns,
        fn (bool $carry, string $p): bool => $carry || preg_match($p, $name) === 1,
        false,
    );

    // Live evidence: "arroz" returns rice cookers first.
    expect($matches($arroz['must_match'], 'Arroz Extra COSTEÑO Bolsa 750g'))->toBeTrue()
        ->and($hitsAny($arroz['must_not_match'], 'Arroz Extra COSTEÑO Bolsa 750g'))->toBeFalse()
        ->and($hitsAny($arroz['must_not_match'], 'Olla Arrocera Oster 1.8 L'))->toBeTrue();
});

it('gives every Plaza Vea entry a category path, a search term and the spike hit rate', function () {
    $mapped = collect((array) config('prices.mapping'))->filter(fn (array $e): bool => isset($e['plazavea']));

    // S0 gate: at least 28 of the 40 catalogue slugs answer on Plaza Vea.
    expect($mapped->count())->toBeGreaterThanOrEqual(28);

    foreach ($mapped as $slug => $entry) {
        expect($entry['plazavea']['category_path'])->toMatch('#^(/\d+)+/$#', "bad path for {$slug}")
            ->and($entry['plazavea']['term'])->toBeString()->not->toBe('');
    }
});

it('leaves a slug without a Plaza Vea entry unmapped rather than guessing', function () {
    // perejil, rocoto and aji-amarillo found nothing usable in the spike.
    expect(config('prices.mapping.perejil.plazavea'))->toBeNull()
        ->and(config('prices.mapping.rocoto.plazavea'))->toBeNull()
        ->and(config('prices.mapping.aji-amarillo'))->toBeNull()
        ->and(config('prices.mapping.arroz.plazavea'))->not->toBeNull();
});

it('only maps INEI labels that exist in the real August 2026 table', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/prices/inei/cuadro19-labels-2026-08.json')), true, flags: JSON_THROW_ON_ERROR);
    $real = array_map(fn (array $row): string => $row['label'].'|'.$row['unit'], $fixture);

    $inei = collect((array) config('prices.mapping'))->filter(fn (array $e): bool => isset($e['inei']));

    // 34 of the 40 catalogue products have an INEI row; the parse rate gate is 90%.
    expect($inei->count())->toBe(34);

    foreach ($inei as $slug => $entry) {
        expect(in_array($entry['inei']['label'].'|'.$entry['inei']['unit'], $real, true))
            ->toBeTrue("INEI label for {$slug} is not in the real table");
    }
});

it('carries a positive curated kg_per_unit wherever a count product relies on mass', function () {
    foreach ((array) config('prices.mapping') as $slug => $entry) {
        if (array_key_exists('kg_per_unit', $entry) && $entry['kg_per_unit'] !== null) {
            expect($entry['kg_per_unit'])->toBeFloat()->toBeGreaterThan(0.0);
        }
    }

    expect(config('prices.mapping.palta.kg_per_unit'))->toBe(0.2)
        ->and(config('prices.mapping.arroz.kg_per_unit'))->toBeNull();
});
