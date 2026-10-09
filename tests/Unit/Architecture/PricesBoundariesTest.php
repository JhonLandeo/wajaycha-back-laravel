<?php

declare(strict_types=1);

/**
 * Executable boundaries for the Prices subdomain (grocery-prices spec,
 * "Purity" / "Phase-1 guarantees preserved").
 *
 * Same two principles as BoundariesTest: every rule here passes today, and
 * exceptions are named. The deciders — the resolver, the cost arithmetic, the
 * attribution formatter — take values in and give decisions out, so a source
 * scan is the honest way to say "no clock, no database, no model": `arch()`
 * matches imports, and `now()` is a global function that needs no import.
 */

use Symfony\Component\Finder\Finder;

/**
 * The pure deciders that exist at this point of the stack. The list is explicit
 * on purpose: a decider added later joins it by deliberate edit, and a rename
 * that drops one of them out of the scan fails the count assertion below
 * instead of silently shrinking the rule to nothing.
 */
const PRICES_PURE_DECIDERS = [
    'PriceResolver.php',
    'CostCalculator.php',
    'AttributionFormatter.php',
    'WholesaleTrendCalculator.php',
    'CalendarDays.php',
];

/**
 * Source of a file with its comments stripped. A docblock that explains the
 * class no longer calls `now()` is documentation, not a call.
 */
function pricesSourceWithoutComments(string $path): string
{
    $source = (string) file_get_contents($path);
    $source = (string) preg_replace('!/\*.*?\*/!s', '', $source);

    return (string) preg_replace('!//.*$!m', '', $source);
}

it('keeps the price deciders free of clocks, databases and models', function () {
    $servicesPath = dirname(__DIR__, 3).'/app/Services/Prices';
    $scanned = [];
    $offenders = [];

    foreach (Finder::create()->files()->in($servicesPath)->depth(0)->name(PRICES_PURE_DECIDERS) as $file) {
        $scanned[] = $file->getFilename();
        $source = pricesSourceWithoutComments($file->getRealPath());

        $forbidden = [
            '/\bnow\s*\(/' => 'now()',
            '/\b(?:Carbon|CarbonImmutable|Date)::(?:now|today|parse\(\s*\))\b/' => 'a static clock read',
            '/\bDB::/' => 'the DB facade',
            '/\bApp\\\\Models\\\\/' => 'an Eloquent model',
            '/\bconfig\s*\(/' => 'config()',
            '/\bcache\s*\(|\bCache::/' => 'the cache',
        ];

        foreach ($forbidden as $pattern => $label) {
            if (preg_match($pattern, $source) === 1) {
                $offenders[] = $file->getFilename().' uses '.$label;
            }
        }
    }

    // Guards the loop above from iterating zero times: every decider must exist.
    sort($scanned);
    $expected = PRICES_PURE_DECIDERS;
    sort($expected);

    expect($scanned)->toBe($expected)
        ->and($offenders)->toBe([]);
});

it('catches an impure decider — the scan itself is not vacuous', function () {
    $dirty = 'final class Dirty { public function f(): void { $x = now(); DB::table("t"); } }';
    $clean = 'final class Clean { public function f(int $x): int { return $x + 1; } }';

    // Same patterns as the rule above, applied to strings whose verdict is known.
    expect(preg_match('/\bnow\s*\(/', $dirty))->toBe(1)
        ->and(preg_match('/\bDB::/', $dirty))->toBe(1)
        ->and(preg_match('/\bnow\s*\(/', $clean))->toBe(0)
        ->and(preg_match('/\bDB::/', $clean))->toBe(0);
});

arch('the price namespaces declare strict types')
    ->expect(['App\Services\Prices', 'App\DTOs\Prices', 'App\Actions\Prices'])
    ->toUseStrictTypes();

arch('the price services never import an Eloquent model')
    ->expect('App\Services\Prices')
    ->not->toUse(['App\Models', 'Illuminate\Support\Facades\DB']);

arch('a price DTO carries values, not Eloquent models')
    ->expect('App\DTOs\Prices')
    ->not->toUse('App\Models');
