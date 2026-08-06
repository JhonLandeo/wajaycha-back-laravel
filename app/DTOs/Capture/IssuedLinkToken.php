<?php

declare(strict_types=1);

namespace App\DTOs\Capture;

/**
 * The one and only moment the plaintext token exists.
 *
 * It is handed to the caller and forgotten; only its hash reaches the database.
 */
final class IssuedLinkToken
{
    public function __construct(
        public readonly string $token,
        public readonly string $deepLink,
        /** Ya formateado ISO-8601: este DTO cruza hacia la respuesta HTTP, no hacia el dominio. */
        public readonly string $expiresAt,
    ) {}
}
