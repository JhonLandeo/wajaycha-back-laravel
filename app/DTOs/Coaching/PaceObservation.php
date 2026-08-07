<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

/**
 * A single fact PaceEvaluator decided is worth reporting.
 *
 * `band` holds one of the two literal values this evaluator can produce
 * (design.md D6): 'over_budget' or 'projected_over'. `cause` is left null here —
 * FinancialCoachingService fills it in after the evaluator returns, from
 * TransactionRepository's merchant breakdown (design.md D5), which is out of this
 * phase's scope.
 */
final class PaceObservation
{
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
    ) {}
}
