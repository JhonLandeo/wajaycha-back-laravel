<?php

declare(strict_types=1);

namespace App\Services\Prices\Parsers;

use App\DTOs\Prices\EmmsaRow;
use App\Exceptions\Prices\PriceSourceFormatChanged;
use DOMDocument;
use DOMXPath;

/**
 * The HTML table EMMSA's `rpt07` endpoint answers with: product, variety, then
 * the minimum, maximum and average price per kg for the requested day. Rows
 * carry names only — there are no product codes in the answer — so selecting
 * the ones that map to a catalogue product is {@see EmmsaVarietySelector}'s job.
 *
 * A table with a header and no rows is a valid answer ("nothing published for
 * that day"); the absence of the table is a changed shape and fails by name. A
 * row whose prices are not numbers is skipped rather than guessed.
 *
 * Pure: text in, rows out.
 */
final class EmmsaTableParser
{
    /**
     * @return list<EmmsaRow>
     */
    public function parse(string $html): array
    {
        if (! str_contains($html, '<table')) {
            throw PriceSourceFormatChanged::missing('emmsa', 'the report table');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $rows = [];

        foreach ((new DOMXPath($document))->query('//tbody/tr') ?: [] as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeName === 'td') {
                    $cells[] = trim((string) preg_replace('/\s+/u', ' ', $cell->textContent));
                }
            }

            if (count($cells) < 5 || ! is_numeric($cells[2]) || ! is_numeric($cells[3]) || ! is_numeric($cells[4])) {
                continue;
            }

            $rows[] = new EmmsaRow(
                product: $cells[0],
                variety: $cells[1],
                min: number_format((float) $cells[2], 4, '.', ''),
                max: number_format((float) $cells[3], 4, '.', ''),
                avg: number_format((float) $cells[4], 4, '.', ''),
            );
        }

        return $rows;
    }
}
