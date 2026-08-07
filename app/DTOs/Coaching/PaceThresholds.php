<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

/**
 * The tunable knobs of the pace rules, read from config('coaching.*') by the caller.
 *
 * Kept as plain values so PaceEvaluator never reaches into config() itself
 * (design.md D1): these thresholds are product judgement, retuned against real
 * messages, and must stay a config edit plus a unit test — never a migration.
 */
final class PaceThresholds
{
    public function __construct(
        public readonly int $minDayForProjection,
        public readonly float $overrunMargin,
        public readonly float $lumpyShare,
        public readonly int $maxObservations,
    ) {}
}
