<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DTOs\Capture\CapturedMedia;
use App\Services\WhatsApp\MetaMediaService;
use App\Services\WhatsAppNotificationService;

/**
 * WhatsApp as the first implementation of the capture port.
 *
 * It owns no transport of its own: the existing Meta services keep doing the work,
 * this only puts them behind the contract a second channel will also satisfy.
 */
class WhatsAppChannel implements CaptureChannel
{
    public function __construct(
        private readonly MetaMediaService $media,
        private readonly WhatsAppNotificationService $notifications,
    ) {}

    public function key(): string
    {
        return 'whatsapp';
    }

    public function fetchMedia(string $mediaReference): ?CapturedMedia
    {
        $media = $this->media->downloadMedia($mediaReference);

        if ($media === null) {
            return null;
        }

        return new CapturedMedia($media['bytes'], $media['mimeType']);
    }

    public function reply(string $externalId, string $text): void
    {
        $this->notifications->sendTextMessage($externalId, $text);
    }
}
