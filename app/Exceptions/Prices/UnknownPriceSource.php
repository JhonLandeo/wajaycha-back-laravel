<?php

declare(strict_types=1);

namespace App\Exceptions\Prices;

use InvalidArgumentException;

/**
 * A command or action was handed a source key that `config/prices.php` does not
 * declare. Raised before anything is read or deleted, so a typo in
 * `prices:purge` can never remove (or skip) the wrong data.
 */
final class UnknownPriceSource extends InvalidArgumentException
{
    public static function named(string $source): self
    {
        return new self("Unknown price source '{$source}'.");
    }
}
