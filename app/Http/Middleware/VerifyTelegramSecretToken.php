<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any webhook delivery that does not carry the secret Telegram was told to
 * send on `setWebhook`.
 *
 * This is middleware and not controller code on purpose. The webhook URL is public;
 * without this check anyone who finds it can post fabricated updates and have them
 * become someone's transactions. The check therefore has to run before any decision
 * is made about the body — before deduplication, before dispatch, before parsing.
 *
 * It is the Telegram equivalent of Meta's HMAC signature, and simpler: a shared
 * secret echoed in a header rather than a computed digest.
 */
class VerifyTelegramSecretToken
{
    public const HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.telegram.secret_token');

        if (trim($expected) === '') {
            // Sin secreto configurado el endpoint queda abierto a cualquiera. Se
            // cierra en vez de abrirse, y se registra distinto del rechazo normal:
            // esto es una alarma de configuracion, no un intento de intrusion.
            Log::error('🚨 Telegram: services.telegram.secret_token no esta configurado; se rechaza toda entrega.');

            return response()->json(['error' => 'Forbidden'], 403);
        }

        $provided = (string) $request->header(self::HEADER, '');

        if (! hash_equals($expected, $provided)) {
            Log::warning('⚠️ Telegram: entrega rechazada por secreto invalido o ausente.');

            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
