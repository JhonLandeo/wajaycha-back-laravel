<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProcessedChannelUpdate;
use Illuminate\Console\Command;

class PruneProcessedChannelUpdates extends Command
{
    /**
     * Telegram gives up redelivering long before this. Keeping rows past the retry
     * window protects nothing and grows a table that is only ever read by an index
     * lookup on the hot path.
     */
    public const RETENTION_DAYS = 7;

    protected $signature = 'processed-channel-updates:prune';

    protected $description = 'Deletes processed channel updates older than the redelivery window';

    public function handle(): int
    {
        $deleted = ProcessedChannelUpdate::query()
            ->where('processed_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();

        $this->info("Se eliminaron {$deleted} entrega(s) procesada(s).");

        return self::SUCCESS;
    }
}
