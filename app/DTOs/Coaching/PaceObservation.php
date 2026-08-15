<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

use App\Enums\BudgetPeriod;

/**
 * A single fact PaceEvaluator decided is worth reporting.
 *
 * `band` holds one of four literal values. Two belong to monthly budgets, which
 * are a rate: 'projected_over' and 'over_budget' (design.md D6). Two belong to
 * yearly budgets, which are an envelope: 'envelope_consumed' and
 * 'envelope_exceeded'. The families never meet on one subject — a category has
 * exactly one `budget_period` — so they share the severity ranks 1 and 2 rather
 * than extending the ladder.
 *
 * `cause` is left null here — FinancialCoachingService fills it in after the
 * evaluator returns, from TransactionRepository's merchant breakdown
 * (design.md D5).
 */
final class PaceObservation
{
    /**
     * @param  float  $spent  the figure compared against `$budget`, in the unit
     *                        `$periodKind` names: month-to-date for a monthly
     *                        band, year-to-date for an envelope one. Always the
     *                        same unit as `$budget`, so `spent / budget` is
     *                        meaningful without knowing which family this is.
     * @param  ?float  $projected  null for every envelope band, always.
     *                             Extrapolating a yearly envelope from one
     *                             month's spend is arithmetic without meaning —
     *                             the defect `budget_period` exists to end.
     * @param  ?int  $monthsRemaining  months left in the calendar year, set only
     *                                 for 'envelope_consumed', where it is part
     *                                 of the sentence rather than decoration.
     */
    public function __construct(
        public readonly string $subjectKey,
        public readonly int $categoryId,
        public readonly string $name,
        public readonly string $band,
        public readonly bool $isLumpy,
        public readonly float $spent,
        public readonly float $budget,
        public readonly ?float $projected,
        public readonly int $dayOfMonth,
        public readonly mixed $cause = null,
        public readonly BudgetPeriod $periodKind = BudgetPeriod::MONTHLY,
        public readonly ?int $monthsRemaining = null,
    ) {}

    public function isEnvelope(): bool
    {
        return $this->periodKind === BudgetPeriod::YEARLY;
    }
}
