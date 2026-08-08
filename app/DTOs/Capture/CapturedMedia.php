<?php

declare(strict_types=1);

namespace App\DTOs\Capture;

/**
 * Raw media retrieved from a capture channel, before any parsing.
 *
 * The channel is responsible for transport only: it says what the bytes are and
 * what they claim to be, never what they mean.
 */
final class CapturedMedia
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
    ) {}
}
