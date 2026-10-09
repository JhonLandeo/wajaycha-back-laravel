<?php

declare(strict_types=1);

namespace App\Services\Prices;

use Carbon\CarbonImmutable;

/**
 * Builds the attribution text that always accompanies a price (spec
 * "Attribution and date always present"). The template lives in
 * `prices.sources.<key>.attribution`; this class only fills it.
 *
 * The month abbreviations are a fixed map, not `translatedFormat()`: the output
 * must not depend on the process locale, and INEI itself writes "SET." for
 * setiembre.
 *
 * Placeholders: `{dd/mm}` (day/month), `{mmm}` (month abbreviation),
 * `{yyyy}` (year) — all taken from the quote's period end.
 */
final class AttributionFormatter
{
    private const MONTHS = [
        1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'oct', 11 => 'nov', 12 => 'dic',
    ];

    public function format(string $template, CarbonImmutable $periodEnd): string
    {
        return strtr($template, [
            '{dd/mm}' => $periodEnd->format('d/m'),
            '{mmm}' => self::MONTHS[$periodEnd->month],
            '{yyyy}' => $periodEnd->format('Y'),
        ]);
    }
}
