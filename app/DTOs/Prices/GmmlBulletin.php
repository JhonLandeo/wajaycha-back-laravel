<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use Carbon\CarbonImmutable;

/**
 * A parsed GMML bulletin: the day it is dated (read from its header) and its
 * product rows.
 */
final class GmmlBulletin
{
    /**
     * @param  list<GmmlRow>  $rows
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly array $rows,
    ) {}
}
