<?php

declare(strict_types=1);

use App\Exceptions\Prices\PriceSourceFormatChanged;
use App\Services\Prices\Parsers\EmmsaTableParser;
use App\Services\Prices\Parsers\EmmsaVarietySelector;

/**
 * EMMSA's wholesale report (rpt07): an HTML table of product, variety and the
 * day's min/max/average S/ per kg. The rows carry NAMES only, no codes, so the
 * mapping selects by product name and a variety pattern (spec "Layer 2").
 */
function emmsaFixture(string $name = 'rpt07-2026-10-07'): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/prices/emmsa/{$name}.html");
}

it('reads every row of the day with trimmed names and numeric prices', function () {
    $rows = (new EmmsaTableParser)->parse(emmsaFixture());

    expect($rows)->toHaveCount(42)
        ->and($rows[0]->product)->toBe('AJI')
        ->and($rows[0]->variety)->toBe('AJI AMARILLO SECO')
        ->and($rows[0]->min)->toBe('14.5000')
        ->and($rows[0]->max)->toBe('15.0000')
        ->and($rows[0]->avg)->toBe('14.8800');
});

it('treats a header-only table as a day with no data, not as a changed shape', function () {
    expect((new EmmsaTableParser)->parse(emmsaFixture('rpt07-empty-2026-10-08')))->toBe([]);
});

it('fails by name when there is no table at all', function () {
    expect(fn () => (new EmmsaTableParser)->parse('<html>Error 500</html>'))->toThrow(PriceSourceFormatChanged::class);
});

it('skips a row whose prices are not numbers instead of storing garbage', function () {
    $html = '<table><tbody><tr><td>PAPA</td><td>PAPA BLANCA</td><td>1.10</td><td>n/d</td><td>1.20</td></tr>'
        .'<tr><td>PAPA</td><td>PAPA AMARILLA</td><td>4.20</td><td>4.50</td><td>4.35</td></tr></tbody></table>';

    $rows = (new EmmsaTableParser)->parse($html);

    expect($rows)->toHaveCount(1)->and($rows[0]->variety)->toBe('PAPA AMARILLA');
});

// -------------------------------------------------------- variety selection

function emmsaQuote(string $slug): ?App\DTOs\Prices\EmmsaQuote
{
    $entry = (require dirname(__DIR__, 3).'/config/prices.php')['mapping'][$slug]['emmsa'];

    return (new EmmsaVarietySelector)->select((new EmmsaTableParser)->parse(emmsaFixture()), $entry['product'], $entry['variety_match']);
}

it('selects the mapped variety of the mapped product', function () {
    $quote = emmsaQuote('papa-blanca');

    expect($quote->avg)->toBe('1.2300')
        ->and($quote->min)->toBe('1.1000')
        ->and($quote->max)->toBe('1.3000')
        ->and($quote->rows)->toBe(1);
});

it('averages several varieties and keeps the widest range', function () {
    // AJO CRIOLLO O NAPURI 3.00-3.50 avg 3.13 and AJO CHINO 3.50-4.00 avg 3.75.
    $quote = emmsaQuote('ajo');

    expect($quote->rows)->toBe(2)
        ->and($quote->avg)->toBe('3.4400')
        ->and($quote->min)->toBe('3.0000')
        ->and($quote->max)->toBe('4.0000');
});

it('leaves out the varieties a negative pattern excludes', function () {
    // TOMATE CHERRY and TOMATE ORGANICO are in the report; only Katia is the staple.
    $quote = emmsaQuote('tomate');

    expect($quote->rows)->toBe(1)->and($quote->avg)->toBe('3.5600');
});

it('does not mistake one product for another with a similar name', function () {
    // The rocoto lives under AJI, never under AJO.
    expect(emmsaQuote('rocoto')->avg)->toBe('4.2400');
});

it('returns nothing when no row matches', function () {
    $selector = new EmmsaVarietySelector;
    $rows = (new EmmsaTableParser)->parse(emmsaFixture());

    expect($selector->select($rows, 'PAPA', '/^PAPA INEXISTENTE/iu'))->toBeNull()
        ->and($selector->select($rows, 'PRODUCTO INEXISTENTE', '/./u'))->toBeNull();
});

it('finds a row for every mapped EMMSA product in the real report of 07/10/2026', function () {
    $mapping = (require dirname(__DIR__, 3).'/config/prices.php')['mapping'];
    $rows = (new EmmsaTableParser)->parse(emmsaFixture());
    $missing = [];

    foreach ($mapping as $slug => $entry) {
        if (isset($entry['emmsa']) && (new EmmsaVarietySelector)->select($rows, $entry['emmsa']['product'], $entry['emmsa']['variety_match']) === null) {
            $missing[] = $slug;
        }
    }

    expect($missing)->toBe([]);
});
