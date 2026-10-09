<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * Something a source offered that was not stored, and why. `$subject` names it
 * (a slug, a table label) and `$reason` is a stable snake_case code, so a run
 * log can count rejections by reason without ever holding a price.
 */
final class Rejection
{
    public function __construct(
        public readonly string $subject,
        public readonly string $reason,
    ) {}
}
