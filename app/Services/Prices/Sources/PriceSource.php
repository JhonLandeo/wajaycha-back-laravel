<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use Carbon\CarbonImmutable;

/**
 * One external price provider (design D2). An adapter does the I/O and hands
 * the text to a pure parser and the candidates to the pure normalizer; it never
 * writes to the database — it returns drafts and the ingest action stores them.
 *
 * A source is asked for its units of work (a Plaza Vea product, a whole EMMSA
 * day) and fetches one unit at a time, so a failure in one never takes the
 * others down with it.
 */
interface PriceSource
{
    /** The source key used everywhere: `plazavea`, `inei`, `emmsa`, `gmml`. */
    public function key(): string;

    /**
     * @return list<PriceFetchUnit>
     */
    public function units(CarbonImmutable $asOf): array;

    /**
     * @throws \Throwable anything that stops the unit from being fetched or read;
     *                    the ingest action turns it into a `failed` run row
     */
    public function fetch(PriceFetchUnit $unit, CarbonImmutable $asOf): SourceFetchResult;
}
