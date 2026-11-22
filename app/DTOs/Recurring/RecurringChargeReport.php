<?php

declare(strict_types=1);

namespace App\DTOs\Recurring;

/**
 * The answer to "¿qué llega solo cada mes o cada año?".
 *
 * The totals are the headline and the charges are the evidence, the same Pareto
 * shape the other readings use. The totals come from every detected charge, not
 * from a truncated list, so a caller that shows a subset still reports the real
 * cost.
 */
final class RecurringChargeReport
{
    /**
     * @param  RecurringCharge[]  $charges  ordered by annual cost, largest first
     */
    public function __construct(
        public readonly array $charges,
        public readonly int $windowMonths,
    ) {}

    public function monthlyTotal(): float
    {
        return array_sum(array_map(
            static fn (RecurringCharge $charge): float => $charge->monthlyEquivalent,
            $this->charges,
        ));
    }

    public function annualTotal(): float
    {
        return array_sum(array_map(
            static fn (RecurringCharge $charge): float => $charge->annualEquivalent,
            $this->charges,
        ));
    }

    /**
     * @return array{
     *   window_months: int,
     *   count: int,
     *   monthly_total: float,
     *   annual_total: float,
     *   charges: array<int, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'window_months' => $this->windowMonths,
            'count' => count($this->charges),
            'monthly_total' => round($this->monthlyTotal(), 2),
            'annual_total' => round($this->annualTotal(), 2),
            'charges' => array_map(
                static fn (RecurringCharge $charge): array => $charge->toArray(),
                $this->charges,
            ),
        ];
    }
}
