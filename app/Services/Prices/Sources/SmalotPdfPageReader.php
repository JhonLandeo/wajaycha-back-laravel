<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use Smalot\PdfParser\Parser;

/**
 * smalot is already a dependency (bank statements) and the spike measured it on
 * the 148-page INEI edition at about ten seconds and 38 MB, so no system binary
 * is needed. Only the matching pages are kept in memory.
 */
final class SmalotPdfPageReader implements PdfPageReader
{
    public function pagesMatching(string $absolutePath, string $pattern): array
    {
        $matches = [];

        foreach ((new Parser)->parseFile($absolutePath)->getPages() as $page) {
            $text = $page->getText();

            if (preg_match($pattern, $text) === 1) {
                $matches[] = $text;
            }
        }

        return $matches;
    }
}
