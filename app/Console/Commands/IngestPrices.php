<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PriceRunStatus;
use App\Jobs\IngestPriceUnit;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\PriceSourceRegistry;
use App\Services\Prices\Sources\PriceSourceLocator;
use App\Services\Prices\Sources\WholesaleFallbackGate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Dispatches one source's units of work to the queue: `prices:ingest plazavea`
 * queues a job per mapped product, `prices:ingest emmsa` a single job.
 *
 * Nothing is fetched here. The command only decides whether to dispatch: a
 * disabled source, or a GMML run for a day EMMSA already covered, is logged as
 * one `skipped` row and queues nothing. Every job re-checks the kill switch when
 * it runs. When a source has many units they are spread out — never closer than
 * five seconds apart, whatever the config says — so the store sees a trickle,
 * not a burst.
 */
class IngestPrices extends Command
{
    protected $signature = 'prices:ingest {source : plazavea, inei, emmsa or gmml}';

    protected $description = 'Queues the price ingestion jobs of one source';

    /** The floor for the gap between two jobs of the same source (spec: >= 5 s). */
    private const MIN_SPACING_SECONDS = 5;

    public function handle(
        PriceSourceRegistry $registry,
        PriceSourceLocator $sources,
        PriceRepositoryContract $repository,
        WholesaleFallbackGate $gate,
    ): int {
        $source = (string) $this->argument('source');

        if (! $registry->knows($source)) {
            $this->error("Unknown price source '{$source}'. Known: ".implode(', ', $registry->sourceKeys()).'.');

            return self::FAILURE;
        }

        if (! $registry->isEnabled($source)) {
            $repository->recordRun($source, 'all', PriceRunStatus::Skipped, details: ['reason' => 'source_disabled']);
            $this->info("{$source} is disabled: nothing queued.");

            return self::SUCCESS;
        }

        $today = CarbonImmutable::now('America/Lima')->startOfDay();

        if ($source === 'gmml' && $gate->emmsaCovers($gate->dayFor($today))) {
            $repository->recordRun($source, 'all', PriceRunStatus::Skipped, details: [
                'reason' => 'emmsa_covers_day',
                'day' => $gate->dayFor($today)->toDateString(),
            ]);
            $this->info('EMMSA already covered yesterday: GMML not needed.');

            return self::SUCCESS;
        }

        $units = $sources->for($source)->units($today);
        $spacing = count($units) > 1
            ? max(self::MIN_SPACING_SECONDS, (int) config("prices.sources.{$source}.spacing_seconds", 0))
            : 0;

        foreach ($units as $index => $unit) {
            IngestPriceUnit::dispatch($source, $unit->key, $today->toDateString())
                ->delay(now()->addSeconds($index * $spacing));
        }

        $this->info(sprintf('%s: %d job(s) queued.', $source, count($units)));

        return self::SUCCESS;
    }
}
