<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Prices\IngestPriceUnitAction;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One unit of work of one price source: a Plaza Vea product, an EMMSA day, an
 * INEI edition check.
 *
 * Kill switch (runbook): set `PRICES_<KEY>_ENABLED=false` in the backend `.env`,
 * `php artisan config:cache`, then `php artisan horizon:terminate` — workers
 * cache config until restarted. A job that was already queued does not need the
 * restart to be harmless: {@see IngestPriceUnitAction} re-reads the flag when
 * the job RUNS, writes a `skipped` row and makes no request.
 *
 * One attempt, never replayed: a retried scrape would repeat requests against a
 * third party, and the next scheduled run is the retry. The timeout is tied to
 * the outbound budget, not tuned by feel — INEI and GMML make three requests on
 * the `gob_pe` profile plus a PDF parse of about ten seconds, and
 * `OutboundHttpBudgetTest` fails if the profiles outgrow this value or if it
 * outgrows the queue's `retry_after`.
 */
class IngestPriceUnit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  string  $asOf  the Lima date (Y-m-d) the run was dispatched for
     */
    public function __construct(
        public readonly string $source,
        public readonly string $unitKey,
        public readonly string $asOf,
    ) {}

    public function handle(IngestPriceUnitAction $action): void
    {
        $action->execute($this->source, $this->unitKey, CarbonImmutable::parse($this->asOf, 'America/Lima')->startOfDay());
    }
}
