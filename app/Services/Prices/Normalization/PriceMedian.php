<?php

declare(strict_types=1);

namespace App\Services\Prices\Normalization;

use InvalidArgumentException;

/**
 * Median of numeric strings, in bcmath so a price never becomes a float. An even
 * count takes the mean of the two middle values, rounded half-up to the stored
 * four decimals.
 */
final class PriceMedian
{
    /**
     * @param  list<string>  $values
     */
    public static function of(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('The median of no values is undefined.');
        }

        usort($values, fn (string $a, string $b): int => bccomp($a, $b, 6));

        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return bcadd($values[$middle], '0', 4);
        }

        return bcadd(bcdiv(bcadd($values[$middle - 1], $values[$middle], 8), '2', 8), '0.00005', 4);
    }
}
