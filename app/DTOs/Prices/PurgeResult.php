<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * What a purge did. `$sourceStillEnabled` lets the caller warn: purging a source
 * that is still switched on only empties it until the next ingest refills it.
 */
final class PurgeResult
{
    public function __construct(
        public readonly string $source,
        public readonly int $deleted,
        public readonly bool $sourceStillEnabled,
    ) {}
}
