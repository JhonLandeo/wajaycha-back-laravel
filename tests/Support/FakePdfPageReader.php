<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Prices\Sources\PdfPageReader;

/**
 * Stands in for smalot in the adapter tests: PDFs are never committed, so the
 * text of the pages is supplied by hand and filtered with the same pattern the
 * real reader receives.
 */
final class FakePdfPageReader implements PdfPageReader
{
    /** @var list<string> */
    public array $readPaths = [];

    /**
     * @param  list<string>  $pages
     */
    public function __construct(private readonly array $pages) {}

    public function pagesMatching(string $absolutePath, string $pattern): array
    {
        $this->readPaths[] = $absolutePath;

        return array_values(array_filter($this->pages, fn (string $page): bool => preg_match($pattern, $page) === 1));
    }
}
