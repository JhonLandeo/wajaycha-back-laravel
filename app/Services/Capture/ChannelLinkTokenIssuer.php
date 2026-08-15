<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DTOs\Capture\IssuedLinkToken;
use App\Models\ChannelLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mints the token the SPA turns into a Telegram deep link.
 *
 * The token is a bearer credential for someone's financial account, so it is
 * random, short-lived and single-use, and only its hash is ever persisted.
 */
class ChannelLinkTokenIssuer
{
    /**
     * A non-expiring link token is a permanent account-takeover primitive: anyone
     * who ever sees the URL can bind their own Telegram account. The window only
     * has to outlast the user switching apps.
     */
    public const TTL_MINUTES = 15;

    public function issue(User $user): IssuedLinkToken
    {
        $token = bin2hex(random_bytes(32));

        // Se construye ANTES de escribir: si la config falta, esto lanza y no
        // queda una fila huerfana que nadie va a poder redimir.
        $deepLink = $this->deepLinkFor($token);

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        ChannelLinkToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        Log::info("🔗 ChannelLinkToken: emitido para el usuario {$user->id}, vence {$expiresAt->toIso8601String()}.");

        return new IssuedLinkToken(
            token: $token,
            deepLink: $deepLink,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    private function deepLinkFor(string $token): string
    {
        $bot = (string) config('services.telegram.bot_username');

        if (trim($bot) === '') {
            // Fallar fuerte y temprano. Sin el username el enlace sale como
            // https://t.me/?start=... : una URL con 200 OK que no lleva a ningun
            // lado y que nadie detecta hasta que un usuario se queja.
            throw new RuntimeException(
                'services.telegram.bot_username no esta configurado: no se puede construir el deep link de vinculacion.'
            );
        }

        return "https://t.me/{$bot}?start={$token}";
    }
}
