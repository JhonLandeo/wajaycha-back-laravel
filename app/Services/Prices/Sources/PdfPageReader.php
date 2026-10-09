<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

/**
 * Reads the text of the pages of a PDF on disk that match a pattern. A seam so
 * the adapters can be tested without a real PDF (PDFs are never committed) and
 * so the one binary-free implementation (smalot) is the only place that knows
 * the library.
 */
interface PdfPageReader
{
    /**
     * @param  string  $pattern  PCRE applied to each page's text
     * @return list<string> the text of every page that matches, in page order
     */
    public function pagesMatching(string $absolutePath, string $pattern): array;
}
