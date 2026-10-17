<?php

declare(strict_types=1);

namespace App\DTOs\PaydayPlan;

/**
 * What one run of the daily send or the reminder did, for the command's output.
 * On a dry run `sent` counts what would have been sent.
 */
final readonly class PaydayRunReport
{
    public function __construct(
        public bool $dryRun = false,
        public bool $disabled = false,
        public int $considered = 0,
        public int $sent = 0,
        public int $failed = 0,
        public int $retried = 0,
        public int $skipped = 0,
        public int $expired = 0,
        public int $errors = 0,
    ) {}
}
