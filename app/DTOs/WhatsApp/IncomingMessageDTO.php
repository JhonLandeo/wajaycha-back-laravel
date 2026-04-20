<?php

declare(strict_types=1);

namespace App\DTOs\WhatsApp;

final readonly class IncomingMessageDTO
{
    public function __construct(
        public string $from,
        public string $type,
        public ?string $content, // Body text or Image ID
        public ?string $mimeType = null
    ) {}
}
