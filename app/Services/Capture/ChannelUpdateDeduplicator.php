<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Models\ProcessedChannelUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a channel delivery is new.
 *
 * It claims by INSERTING, not by asking first. A "have I seen this?" query followed
 * by an insert leaves a window where two concurrent workers both read nothing and
 * both dispatch, which is exactly the duplicate this class exists to prevent. The
 * unique index is the arbiter; this is just the code that lets it decide.
 *
 * Rejecting at the edge is also what keeps a redelivery free: dropping it here costs
 * one index lookup, while noticing inside the job would already have paid for a
 * Gemini call.
 */
class ChannelUpdateDeduplicator
{
    /**
     * Claims the delivery. Returns true the first time, false for every replay.
     */
    public function claim(string $channel, string $externalUpdateId): bool
    {
        try {
            // Savepoint propio: en PostgreSQL una violacion de constraint aborta la
            // transaccion que la contiene, asi que sin esto atrapar la excepcion
            // dejaria inservible cualquier transaccion que nos envuelva.
            DB::transaction(fn () => ProcessedChannelUpdate::create([
                'channel' => $channel,
                'external_update_id' => $externalUpdateId,
                'processed_at' => now(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException) {
            Log::info("♻️ {$channel}: entrega {$externalUpdateId} ya procesada; se descarta sin despachar.");

            return false;
        }
    }
}
