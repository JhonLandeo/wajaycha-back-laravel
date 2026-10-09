<?php

declare(strict_types=1);

namespace App\Exceptions\Prices;

use RuntimeException;

/**
 * The source answered correctly but had nothing for the day we asked about
 * (EMMSA publishes D-1 late, or not at all on a holiday). For a daily source it
 * is a failed run on purpose: the canary trips, and the GMML fallback — which
 * only stands down for a successful EMMSA run — is allowed to fill the day.
 */
final class PriceSourceNoData extends RuntimeException
{
    public static function forDay(string $source, string $day): self
    {
        return new self("Price source '{$source}' returned no data for {$day}.");
    }
}
