<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * The estimate set against what is left of the ceiling. When the estimate is
 * partial this is a LOWER BOUND on spend (unpriced lines are excluded), and the
 * consumer must say so.
 */
final class CeilingComparison
{
    public function __construct(
        public readonly float $remaining,
        public readonly float $remainingAfterEstimate,
        public readonly bool $wouldExceed,
    ) {}

    /**
     * @return array{remaining: float, remaining_after_estimate: float, would_exceed: bool}
     */
    public function toArray(): array
    {
        return [
            'remaining' => $this->remaining,
            'remaining_after_estimate' => $this->remainingAfterEstimate,
            'would_exceed' => $this->wouldExceed,
        ];
    }
}
