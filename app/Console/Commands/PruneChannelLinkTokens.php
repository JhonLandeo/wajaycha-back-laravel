<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChannelLinkToken;
use Illuminate\Console\Command;

class PruneChannelLinkTokens extends Command
{
    protected $signature = 'channel-link-tokens:prune';

    protected $description = 'Deletes expired and already redeemed channel link tokens';

    /**
     * Spent and expired tokens are worthless but not harmless: they are a growing
     * table of hashes tied to user ids, kept for no reason.
     */
    public function handle(): int
    {
        $deleted = ChannelLinkToken::query()
            ->where('expires_at', '<', now())
            ->orWhereNotNull('redeemed_at')
            ->delete();

        $this->info("Se eliminaron {$deleted} token(s) de vinculacion.");

        return self::SUCCESS;
    }
}
