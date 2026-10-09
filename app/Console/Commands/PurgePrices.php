<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Prices\PurgePriceSourceAction;
use App\Exceptions\Prices\UnknownPriceSource;
use Illuminate\Console\Command;

/**
 * Takedown for one price source: `prices:purge plazavea` deletes every
 * observation it ever produced (the 24-48 hour commitment of ADR-0010).
 *
 * Runbook, in order: set `PRICES_<KEY>_ENABLED=false` in the backend `.env`,
 * `php artisan config:cache`, `php artisan horizon:terminate`, then this
 * command. An unknown source fails before anything is deleted; running it
 * twice is harmless; run-log rows are kept (they hold no prices).
 */
class PurgePrices extends Command
{
    protected $signature = 'prices:purge {source : plazavea, inei, emmsa or gmml}';

    protected $description = 'Deletes every stored observation of one price source';

    public function handle(PurgePriceSourceAction $action): int
    {
        try {
            $result = $action->execute((string) $this->argument('source'));
        } catch (UnknownPriceSource $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Deleted {$result->deleted} observation(s) of {$result->source}.");

        if ($result->sourceStillEnabled) {
            $this->warn("{$result->source} is still enabled and will be refilled by the next ingest. Set PRICES_".strtoupper($result->source).'_ENABLED=false, run `php artisan config:cache` and `php artisan horizon:terminate`.');
        }

        return self::SUCCESS;
    }
}
