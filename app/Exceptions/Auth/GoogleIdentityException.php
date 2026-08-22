<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * Something went wrong turning a Google credential into an identity.
 *
 * Each case carries its own HTTP status and its own message for the user,
 * because they are not the same kind of failure and answering all of them with
 * one 401 would be a lie. A forged token is the caller's fault; Google's key
 * endpoint being unreachable is not, and telling that user "credenciales
 * inválidas" would send them to reset a password that works fine.
 *
 * `getMessage()` stays technical and goes to the logs. `$userMessage` is the one
 * that reaches the browser, and it never repeats what the token contained.
 */
final class GoogleIdentityException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $userMessage,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'services.google.client_id is empty; Google Sign-In cannot verify anything.',
            503,
            'El inicio de sesión con Google no está disponible en este momento.',
        );
    }

    public static function keysUnavailable(string $detail): self
    {
        return new self(
            "Could not fetch Google's signing keys: {$detail}",
            503,
            'No pudimos verificar tu cuenta de Google. Intentá de nuevo en un momento.',
        );
    }

    public static function malformedCredential(string $detail): self
    {
        return new self("Malformed Google credential: {$detail}", 401, self::REJECTED);
    }

    public static function invalidSignature(string $detail): self
    {
        return new self("Google credential failed verification: {$detail}", 401, self::REJECTED);
    }

    public static function untrustedIssuer(string $issuer): self
    {
        return new self("Unexpected ID token issuer: {$issuer}", 401, self::REJECTED);
    }

    public static function audienceMismatch(): self
    {
        return new self('ID token was not issued for this application.', 401, self::REJECTED);
    }

    public static function missingClaim(string $claim): self
    {
        return new self("ID token has no usable `{$claim}` claim.", 401, self::REJECTED);
    }

    /**
     * The one case that is neither an attack nor an outage: a real Google account
     * whose email Google itself has not confirmed. Rejecting it is what makes
     * linking-by-email safe — see {@see \App\Actions\Auth\ResolveGoogleUserAction}.
     */
    public static function emailNotVerified(): self
    {
        return new self(
            'Google reports this account\'s email as unverified.',
            401,
            'Google no confirmó el email de esa cuenta, así que no podemos usarla para entrar.',
        );
    }

    /**
     * The email is ours but it is already tied to a different Google account.
     * 409, not 401: nothing about the request is wrong, the two records disagree
     * and a human has to untangle it.
     */
    public static function alreadyLinkedElsewhere(): self
    {
        return new self(
            'Email is already linked to a different Google account.',
            409,
            'Ese email ya está vinculado a otra cuenta de Google. Escribinos para resolverlo.',
        );
    }

    /**
     * Every rejection the caller could have caused answers with the same words.
     * Telling a forger which check failed hands them the map.
     */
    private const REJECTED = 'No pudimos validar tu cuenta de Google. Probá de nuevo.';
}
