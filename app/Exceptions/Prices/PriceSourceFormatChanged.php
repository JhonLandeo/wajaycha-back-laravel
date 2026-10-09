<?php

declare(strict_types=1);

namespace App\Exceptions\Prices;

use RuntimeException;

/**
 * A source answered, but not in the shape its parser was written against: the
 * table header is gone, the page moved, a column disappeared. It is a named
 * failure on purpose — the ingestion run is recorded `failed` with this message
 * and the canary trips, instead of the parser quietly returning zero rows.
 */
final class PriceSourceFormatChanged extends RuntimeException
{
    public static function missing(string $source, string $what): self
    {
        return new self("Price source '{$source}' changed shape: {$what} not found.");
    }
}
