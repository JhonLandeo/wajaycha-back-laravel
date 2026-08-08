<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Models\ChannelIdentity;
use App\Models\ChannelLinkToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a link token into a channel identity.
 *
 * Every refusal — forged, expired, already spent, account already claimed — returns
 * the same bare `false`. The caller cannot tell them apart, so it cannot be used to
 * probe which tokens are real.
 */
class ChannelLinkTokenRedeemer
{
    public function redeem(string $channel, string $externalId, string $token): bool
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($channel, $externalId, $hash): bool {
            $candidate = ChannelLinkToken::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($candidate === null) {
                return $this->refuse('token inexistente');
            }

            // Redundant after a lookup by hash, but it keeps the comparison itself
            // free of early-exit timing differences.
            if (! hash_equals($candidate->token_hash, $hash)) {
                return $this->refuse('hash no coincide');
            }

            if ($candidate->redeemed_at !== null) {
                return $this->refuse('token ya gastado');
            }

            if ($candidate->expires_at->isPast()) {
                return $this->refuse('token vencido');
            }

            $alreadyClaimed = ChannelIdentity::query()
                ->where('channel', $channel)
                ->where('external_id', $externalId)
                ->exists();

            if ($alreadyClaimed) {
                // Deliberately does NOT spend the token. Otherwise anyone who guessed
                // a victim's chat id could burn their link before they used it.
                return $this->refuse('la cuenta de canal ya tiene dueño');
            }

            try {
                // Savepoint propio. El lock de arriba es sobre la fila del TOKEN, no
                // sobre la identidad: dos tokens distintos y validos pueden correr por
                // la misma cuenta de canal y pasar ambos la verificacion anterior. El
                // indice unico es el arbitro real, y en PostgreSQL una violacion sin
                // savepoint aborta la transaccion entera.
                DB::transaction(fn () => ChannelIdentity::create([
                    'user_id' => $candidate->user_id,
                    'channel' => $channel,
                    'external_id' => $externalId,
                    'linked_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                return $this->refuse('la cuenta de canal fue reclamada concurrentemente');
            }

            $candidate->update(['redeemed_at' => now()]);

            Log::info("🔗 ChannelIdentity: usuario {$candidate->user_id} vinculo una cuenta de {$channel}.");

            return true;
        });
    }

    /** Logs the reason for operators; the caller only ever sees false. */
    private function refuse(string $reason): bool
    {
        Log::warning("⚠️ ChannelLinkToken: canje rechazado ({$reason}).");

        return false;
    }
}
