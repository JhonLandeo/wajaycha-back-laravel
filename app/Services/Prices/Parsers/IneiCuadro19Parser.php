<?php

declare(strict_types=1);

namespace App\Services\Prices\Parsers;

use App\DTOs\Prices\IneiParseResult;
use App\DTOs\Prices\IneiRow;
use App\DTOs\Prices\Rejection;
use App\Exceptions\Prices\PriceSourceFormatChanged;
use Carbon\CarbonImmutable;

/**
 * Cuadro N.19 of INEI's monthly "Indicadores de Precios de la Economia": average
 * monthly prices of the Lima CPI basket, thirteen months per product.
 *
 * Pure: text in, rows out. It does not know which pages hold the table (the
 * source picks them) nor which labels matter (the mapping does). What it knows
 * is how smalot mangles this particular table, found against the real August
 * 2026 edition:
 *
 *  - the title and the "AGOSTO 2025 - AGOSTO 2026" header land at the END of the
 *    page text, so the period is read from the header and never from a file name;
 *  - a label can be split over lines with a footnote marker in the middle
 *    ("AZUCAR BLANCA", "1/", "KILOGRAMO");
 *  - two products share a line, so rows are found by splitting on runs of
 *    thirteen numbers; the text between two runs is "LABEL UNIT";
 *  - neighbouring numbers are sometimes glued ("26.4726,71") and decimals are
 *    printed with both "." and "," and, occasionally, doubled ("4,,86").
 *
 * A price is accepted only when it ends in exactly two decimals after the last
 * separator; anything else ("3.330") rejects the row instead of guessing a scale.
 */
final class IneiCuadro19Parser
{
    private const MONTHS = [
        'ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6,
        'JULIO' => 7, 'AGOSTO' => 8, 'SETIEMBRE' => 9, 'SEPTIEMBRE' => 9, 'OCTUBRE' => 10,
        'NOVIEMBRE' => 11, 'DICIEMBRE' => 12,
    ];

    /** Longest first, so "LATA G," is not read as "LATA". */
    private const UNITS = ['KILOGRAMO', 'LITRO', 'LATA G,', 'BOT,MED,', 'LATA'];

    private const NUMBER = '\d+(?:[.,]{1,2}\d+)?';

    private const MONTH_COLUMNS = 13;

    /**
     * @param  float  $maxMonthOverMonthChange  |latest / previous - 1| above this quarantines a row
     */
    public function __construct(private readonly float $maxMonthOverMonthChange) {}

    public function parse(string $text): IneiParseResult
    {
        [$periodStart, $periodEnd] = $this->period($text);

        $normalized = (string) preg_replace('/\s+/u', ' ', $text);
        // Glued neighbours: split "26.4726,71" into "26.47 26,71".
        $normalized = (string) preg_replace('/(\d+[.,]\d{2})(?=\d+[.,]\d{2}(?!\d))/u', '$1 ', $normalized);

        $run = '((?:'.self::NUMBER.'\s+){'.(self::MONTH_COLUMNS - 1).'}'.self::NUMBER.')';
        $parts = preg_split('/'.$run.'/u', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $rows = [];
        $rejections = [];

        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            [$label, $unit] = $this->labelAndUnit($parts[$i]);
            $cells = preg_split('/\s+/', trim($parts[$i + 1])) ?: [];

            $price = $this->price($cells[self::MONTH_COLUMNS - 1] ?? '');
            $previous = $this->price($cells[self::MONTH_COLUMNS - 2] ?? '');

            if ($price === null || $previous === null) {
                $rejections[] = new Rejection($label, 'unparseable_price');

                continue;
            }

            $rows[] = new IneiRow($label, $unit, $price, $previous, $this->jumpsTooFar($price, $previous));
        }

        return new IneiParseResult($periodStart, $periodEnd, $rows, $rejections);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(string $text): array
    {
        $months = implode('|', array_keys(self::MONTHS));

        if (preg_match('/\b(?:'.$months.')\s+\d{4}\s*-\s*('.$months.')\s+(\d{4})\b/u', $text, $m) !== 1) {
            throw PriceSourceFormatChanged::missing('inei', 'the period header');
        }

        $start = CarbonImmutable::create((int) $m[2], self::MONTHS[$m[1]], 1, 0, 0, 0, 'America/Lima');

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function labelAndUnit(string $blob): array
    {
        $blob = trim((string) preg_replace('/\b\d\/\s*/u', '', $blob));

        // The first row of a page drags the column header in front of it: keep
        // what follows the last abbreviated month token.
        if (preg_match('/^.*\b(?:ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SET|OCT|NOV|DIC)\.\s*(.*)$/su', $blob, $m) === 1) {
            $blob = trim($m[1]);
        }

        $unit = '';
        foreach (self::UNITS as $candidate) {
            if (str_ends_with($blob, ' '.$candidate) || $blob === $candidate) {
                $unit = $candidate;
                $blob = trim(substr($blob, 0, -strlen($candidate)));
                break;
            }
        }

        // Footnote text or a trailer can precede the label: the label is the
        // trailing run of capitals.
        if (preg_match('/([A-ZÁÉÍÓÚÑÜ][A-ZÁÉÍÓÚÑÜ0-9 ()\/,.\-]*)$/u', $blob, $m) === 1) {
            $blob = trim($m[1]);
        }

        return [$blob, $unit];
    }

    /**
     * A repaired price as a four-decimal string, or null when the cell cannot be
     * trusted.
     */
    private function price(string $cell): ?string
    {
        $cell = (string) preg_replace('/,{2,}/', ',', $cell);

        if (preg_match('/^(\d+)[.,](\d{2})$/', $cell, $m) !== 1) {
            return null;
        }

        return $m[1].'.'.$m[2].'00';
    }

    private function jumpsTooFar(string $price, string $previous): bool
    {
        if ((float) $previous <= 0.0) {
            return true;
        }

        return abs((float) $price / (float) $previous - 1) > $this->maxMonthOverMonthChange;
    }
}
