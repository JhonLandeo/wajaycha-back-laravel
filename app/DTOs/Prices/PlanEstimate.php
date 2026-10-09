<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * What the whole list is estimated to cost (spec "Partial-aware estimate").
 *
 * `$total` sums the fresh and stale lines only. It is 0 for an empty list and
 * null — never 0 — when lines exist but none is priced. `$isPartial` is true
 * exactly when at least one line is unpriced.
 */
final class PlanEstimate
{
    public function __construct(
        public readonly ?float $total,
        public readonly int $pricedLines,
        public readonly int $unpricedLines,
        public readonly bool $isPartial,
        public readonly ?CeilingComparison $ceilingComparison,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'priced_lines' => $this->pricedLines,
            'unpriced_lines' => $this->unpricedLines,
            'is_partial' => $this->isPartial,
            'ceiling_comparison' => $this->ceilingComparison?->toArray(),
        ];
    }
}
