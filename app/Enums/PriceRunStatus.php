<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one row of `price_ingestion_runs` (one source, one unit of work).
 */
enum PriceRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Purged = 'purged';
}
