<?php

declare(strict_types=1);

namespace App\Exceptions\Prices;

use RuntimeException;

/**
 * A price source's canary tripped. It is never thrown to a user: the canary
 * command reports it to Sentry and fails its cron check-in, which is the alert.
 */
final class PriceCanaryTripped extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly string $reason,
    ) {
        parent::__construct("Price source '{$source}' canary tripped: {$reason}.");
    }
}
