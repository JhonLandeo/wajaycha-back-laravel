<?php

declare(strict_types=1);

namespace App\Services\Prices\Parsers;

use App\DTOs\Prices\EmmsaQuote;
use App\DTOs\Prices\EmmsaRow;

/**
 * Picks, from the whole day's EMMSA report, the lines that belong to one
 * catalogue product: same product name (case-insensitive) and a variety that
 * matches the curated pattern. The quote is the mean of the matching averages
 * with the widest min/max, in bcmath.
 *
 * Pure: rows and a mapping in, a quote or null out.
 */
final class EmmsaVarietySelector
{
    /**
     * @param  list<EmmsaRow>  $rows
     * @param  string  $varietyMatch  PCRE with delimiters
     */
    public function select(array $rows, string $product, string $varietyMatch): ?EmmsaQuote
    {
        $matching = array_values(array_filter(
            $rows,
            fn (EmmsaRow $row): bool => strcasecmp($row->product, $product) === 0
                && preg_match($varietyMatch, $row->variety) === 1,
        ));

        if ($matching === []) {
            return null;
        }

        $sum = '0';
        $min = $matching[0]->min;
        $max = $matching[0]->max;

        foreach ($matching as $row) {
            $sum = bcadd($sum, $row->avg, 6);
            $min = bccomp($row->min, $min, 6) < 0 ? $row->min : $min;
            $max = bccomp($row->max, $max, 6) > 0 ? $row->max : $max;
        }

        return new EmmsaQuote(
            avg: bcadd(bcdiv($sum, (string) count($matching), 8), '0.00005', 4),
            min: $min,
            max: $max,
            rows: count($matching),
        );
    }
}
