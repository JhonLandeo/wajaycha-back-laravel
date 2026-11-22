<?php

declare(strict_types=1);

namespace App\DTOs\Recurring;

use App\Enums\RecurringCadence;

/**
 * One merchant's charge that arrives on its own, with the cost already annualised.
 *
 * A value object rather than a model because there is no table: the whole
 * reading is derived from movements (ADR-0051). That also makes it the shape a
 * unit test can assert against a fixed series without a database.
 *
 * `amount` is the LAST charge, not the average. A subscription that raised its
 * price two months ago costs what it costs today; the average would describe a
 * price that no longer exists. `averageAmount` travels separately so a reader
 * can see how much the bill moved without turning that movement into the cost.
 */
final class RecurringCharge
{
    public function __construct(
        public readonly string $merchant,
        public readonly RecurringCadence $cadence,
        public readonly float $amount,
        public readonly float $averageAmount,
        public readonly int $occurrences,
        public readonly string $lastChargedOn,
        public readonly string $nextExpectedOn,
        public readonly float $monthlyEquivalent,
        public readonly float $annualEquivalent,
    ) {}

    /**
     * @return array{
     *   merchant: string,
     *   cadence: string,
     *   cadence_label: string,
     *   amount: float,
     *   average_amount: float,
     *   occurrences: int,
     *   last_charged_on: string,
     *   next_expected_on: string,
     *   monthly_equivalent: float,
     *   annual_equivalent: float
     * }
     */
    public function toArray(): array
    {
        return [
            'merchant' => $this->merchant,
            'cadence' => $this->cadence->value,
            'cadence_label' => $this->cadence->label(),
            'amount' => round($this->amount, 2),
            'average_amount' => round($this->averageAmount, 2),
            'occurrences' => $this->occurrences,
            'last_charged_on' => $this->lastChargedOn,
            'next_expected_on' => $this->nextExpectedOn,
            'monthly_equivalent' => round($this->monthlyEquivalent, 2),
            'annual_equivalent' => round($this->annualEquivalent, 2),
        ];
    }
}
