<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use RuntimeException;

/**
 * A source answered with an error status (or not at all, after the profile's
 * retries). The message carries the status and the URL host and path only —
 * it lands in the run log, which holds no third-party content.
 */
final class SourceRequestFailed extends RuntimeException
{
    public static function status(string $source, int $status, string $url): self
    {
        return new self("Price source '{$source}' answered HTTP {$status} for {$url}.");
    }
}
