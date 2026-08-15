<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Capture\CapturedMedia;
use App\Services\Capture\CaptureChannel;

/**
 * A capture channel that records instead of transmitting.
 *
 * The job tests care about WHAT the sender is told and WHETHER the media was
 * fetched, not about how Meta's HTTP API is shaped. Faking the transport at the
 * port rather than at the HTTP client keeps those assertions about behaviour.
 */
class RecordingCaptureChannel implements CaptureChannel
{
    /** @var array<int, array{externalId: string, text: string}> */
    public array $replies = [];

    /** @var array<int, string> */
    public array $mediaRequested = [];

    public function __construct(private readonly ?CapturedMedia $media = null) {}

    public function key(): string
    {
        return 'whatsapp';
    }

    public function fetchMedia(string $mediaReference): ?CapturedMedia
    {
        $this->mediaRequested[] = $mediaReference;

        return $this->media;
    }

    public function reply(string $externalId, string $text): void
    {
        $this->replies[] = ['externalId' => $externalId, 'text' => $text];
    }

    public function lastReply(): ?string
    {
        return $this->replies === [] ? null : end($this->replies)['text'];
    }
}
