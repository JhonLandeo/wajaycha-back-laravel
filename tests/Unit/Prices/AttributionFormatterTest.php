<?php

declare(strict_types=1);

use App\Services\Prices\AttributionFormatter;
use Carbon\CarbonImmutable;

const ATTRIBUTION_PLAZAVEA = 'Precio online de Plaza Vea al {dd/mm}';
const ATTRIBUTION_INEI = 'Promedio Lima INEI, {mmm} {yyyy}';

it('renders the Plaza Vea text from the period end', function (string $periodEnd, string $expected) {
    expect((new AttributionFormatter)->format(ATTRIBUTION_PLAZAVEA, CarbonImmutable::parse($periodEnd)))->toBe($expected);
})->with([
    ['2026-10-05', 'Precio online de Plaza Vea al 05/10'],
    ['2026-01-02', 'Precio online de Plaza Vea al 02/01'],
    ['2026-12-31', 'Precio online de Plaza Vea al 31/12'],
]);

it('renders the INEI text with a fixed Spanish month abbreviation', function (string $periodEnd, string $expected) {
    expect((new AttributionFormatter)->format(ATTRIBUTION_INEI, CarbonImmutable::parse($periodEnd)))->toBe($expected);
})->with([
    ['2026-08-31', 'Promedio Lima INEI, ago 2026'],
    ['2026-01-31', 'Promedio Lima INEI, ene 2026'],
    ['2025-09-30', 'Promedio Lima INEI, set 2025'],
]);

it('uses the same twelve abbreviations whatever the process locale', function () {
    $formatter = new AttributionFormatter;
    $months = [];

    foreach (range(1, 12) as $month) {
        $date = CarbonImmutable::create(2026, $month, 1);
        $months[] = $formatter->format('{mmm}', $date);
    }

    expect($months)->toBe(['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic']);
});

it('leaves a template without placeholders untouched', function () {
    expect((new AttributionFormatter)->format('Fuente oficial', CarbonImmutable::parse('2026-10-05')))->toBe('Fuente oficial');
});
