<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use App\Repositories\Contracts\PriceRepositoryContract;
use Carbon\CarbonImmutable;

/**
 * The one rule that keeps the two wholesale sources from being mixed: GMML only
 * runs for a day EMMSA did not cover. "Covered" means EMMSA has a `success` or
 * `partial` run for that day; a failed or missing one leaves the day to GMML.
 *
 * The command asks it before dispatching (so the skip is logged without a job)
 * and the GMML adapter asks it again when the job runs.
 */
final class WholesaleFallbackGate
{
    public function __construct(private readonly PriceRepositoryContract $repository) {}

    public function emmsaCovers(CarbonImmutable $day): bool
    {
        return $this->repository->hasCompletedRunForDay('emmsa', $day->toDateString());
    }

    /** The day the wholesale sources ingest for a run dispatched on `$asOf`: yesterday, Lima. */
    public function dayFor(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->setTimezone('America/Lima')->startOfDay()->subDay();
    }
}
