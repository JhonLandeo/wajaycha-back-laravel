<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use App\DTOs\Prices\PlanEstimate;

/**
 * `ShoppingPlanService::plan()`'s whole output (design.md "Output"). Never
 * persisted — computed on every read (spec.md "Weekly list derivation").
 *
 * `$lines`/`$covered` descend from `(habits, pantry, week)` only, and
 * `$ceiling`/`$ceilingState` descend from `(ceiling, week)` only — the two
 * share no term (design.md D5's structural q6 guarantee). An exceeded
 * ceiling can therefore never alter, reorder or hide a line.
 */
final class WeeklyShoppingPlan
{
    /**
     * @param  array<int, ShoppingListLine>  $lines
     * @param  array<int, CoveredLine>  $covered
     */
    public function __construct(
        public readonly PlanningWeek $week,
        public readonly array $lines,
        public readonly array $covered,
        public readonly ?GroceryCeilingReading $ceiling,
        public readonly string $ceilingState,
        public readonly ?string $ceilingResolution,
        public readonly ?PlanEstimate $estimate = null,
    ) {}

    /**
     * No API Resources layer (design.md D7) — the controller hands this
     * straight to `response()->json(['data' => …])`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'week' => [
                'starts_on' => $this->week->startsOn->toDateString(),
                'ends_on' => $this->week->endsOn->toDateString(),
            ],
            'lines' => array_map(
                static fn (ShoppingListLine $line): array => $line->toArray(),
                $this->lines
            ),
            'covered' => array_map(
                static fn (CoveredLine $line): array => $line->toArray(),
                $this->covered
            ),
            'ceiling_state' => $this->ceilingState,
            'ceiling_resolution' => $this->ceilingResolution,
            'ceiling' => $this->ceiling?->toArray(),
            'estimate' => $this->estimate?->toArray(),
        ];
    }
}
