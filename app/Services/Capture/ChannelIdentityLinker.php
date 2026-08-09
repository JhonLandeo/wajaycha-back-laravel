<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Models\ChannelIdentity;
use App\Models\User;
use App\Support\Redact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates the channel identity that lets a capture be attributed to a User.
 *
 * Registration writes users.whatsapp_phone, but capture resolves through
 * channel_identities. Without this the two never meet and every user who registers
 * after the identity migration is invisible to the bot.
 */
class ChannelIdentityLinker
{
    public function __construct(private readonly PhoneNormaliser $normaliser) {}

    /**
     * Link a WhatsApp number to its user. Returns null when the number cannot be
     * normalised or the account is already claimed — registration must not fail
     * because of it, but the gap has to be visible.
     */
    public function linkWhatsApp(User $user, ?string $phone): ?ChannelIdentity
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $externalId = $this->normaliser->normalise($phone);

        if ($externalId === null) {
            // El numero se reemplaza por su seudonimo estable: alcanza para
            // correlacionar las lineas de un mismo usuario sin publicar como
            // contactarlo. El largo se conserva porque es lo unico de la forma
            // original que sirve para diagnosticar por que no normalizo.
            Log::warning('⚠️ ChannelIdentity: el telefono '.Redact::id($phone).' ('.Redact::text($phone)
                .") del usuario {$user->id} no se pudo normalizar; la cuenta queda sin vincular.");

            return null;
        }

        $existing = ChannelIdentity::query()
            ->where('channel', 'whatsapp')
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            // Una cuenta de canal pertenece a un solo usuario. Reasignarla dejaria las
            // transacciones del dueño anterior colgando de un remitente ajeno.
            Log::warning('⚠️ ChannelIdentity: '.Redact::id($externalId)." ya pertenece al usuario {$existing->user_id}; no se vincula al usuario {$user->id}.");

            return null;
        }

        try {
            // El insert va en su propia transaccion a proposito. En PostgreSQL una
            // violacion de constraint aborta la transaccion entera: sin este savepoint,
            // atrapar la excepcion deja inutilizable cualquier transaccion que nos
            // envuelva y la siguiente consulta falla con 25P02.
            return DB::transaction(fn () => ChannelIdentity::create([
                'user_id' => $user->id,
                'channel' => 'whatsapp',
                'external_id' => $externalId,
                'linked_at' => now(),
                'legacy_whatsapp_phone' => $phone,
            ]));
        } catch (UniqueConstraintViolationException) {
            // La verificacion de arriba y este insert no son atomicos: dos registros
            // concurrentes con el mismo numero pueden pasar ambos el SELECT. El indice
            // unico es el arbitro real. El perdedor no vincula, pero su registro no
            // puede fallar por eso.
            Log::warning('⚠️ ChannelIdentity: '.Redact::id($externalId)." fue reclamado concurrentemente; el usuario {$user->id} queda sin vincular.");

            return null;
        }
    }
}
