<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceRunStatus;
use Carbon\CarbonImmutable;

/**
 * What the canary needs to know about one source, read from the database and
 * handed to the pure evaluator: the outcome of its latest real run (success,
 * partial or failed — skipped and running rows say nothing), the rows that run
 * wrote, the date of its newest usable observation and how many usable rows
 * share that date.
 */
final class CanarySnapshot
{
    public function __construct(
        public readonly ?PriceRunStatus $latestRunStatus,
        public readonly int $latestRunRows,
        public readonly ?CarbonImmutable $newestPeriodEnd,
        public readonly int $newestBatchRows,
    ) {}
}
