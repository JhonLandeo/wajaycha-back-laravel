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

    /**
     * Gives the claim back, so the delivery can be accepted again.
     *
     * A claim is a promise that the work was taken on. When whatever was meant
     * to take it on never got the chance — a queue that will not accept the
     * dispatch — the promise is false, and leaving the row behind means the
     * delivery can never be retried: the sender's movement is gone with no error
     * anywhere, because the redelivery is refused by this very class.
     *
     * Releasing is only correct BEFORE the work is handed off. Once the job is
     * queued the row stays, whatever becomes of the job — that is the duplicate
     * this class exists to prevent.
     */
    public function release(string $channel, string $externalUpdateId): void
    {
        ProcessedChannelUpdate::query()
            ->where('channel', $channel)
            ->where('external_update_id', $externalUpdateId)
            ->delete();

        Log::warning("↩️ {$channel}: se libera la entrega {$externalUpdateId} para que pueda reintentarse.");
    }
}
