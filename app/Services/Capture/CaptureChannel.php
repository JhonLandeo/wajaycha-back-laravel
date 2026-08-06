<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DTOs\Capture\CapturedMedia;

/**
 * The capture port.
 *
 * Everything downstream of a capture — Gemini parsing, ParsedReceiptDTO, Entity
 * Resolution, Categorisation — is already channel-agnostic. Only these three
 * responsibilities are genuinely per-channel, so the port has exactly three methods.
 */
interface CaptureChannel
{
    /** Stable channel key, persisted as the channel discriminator. */
    public function key(): string;

    /** Retrieve the bytes an inbound capture refers to, or null if unavailable. */
    public function fetchMedia(string $mediaReference): ?CapturedMedia;

    /** Reply to the sender in their own channel. */
    public function reply(string $externalId, string $text): void;
}
