<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use Carbon\CarbonImmutable;

/**
 * What the Cuadro N.19 parser found: the month the table covers (read from its
 * header), the usable rows and the rows it had to reject.
 */
final class IneiParseResult
{
    /**
     * @param  list<IneiRow>  $rows
     * @param  list<Rejection>  $rejections
     */
    public function __construct(
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly array $rows,
        public readonly array $rejections,
    ) {}
}
