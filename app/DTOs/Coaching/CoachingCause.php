<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

use Carbon\CarbonImmutable;

/**
 * What `PaceObservation::$cause` (design.md §5.1/§9) holds once
 * `FinancialCoachingService` fills it in from `TransactionRepository`'s merchant
 * breakdown (design.md D5). One shape serves both message forms in design.md §6:
 *
 * - Linear (`projected_over`, `over_budget` not lumpy): `$merchantName`, `$amount`
 *   (the merchant's month-to-date total) and `$frequency` (its transaction count)
 *   come from `topMerchantsForCategoryMonth()`.
 * - Lumpy (`over_budget`, `isLumpy`): `$merchantName`, `$amount` (the single
 *   dominant transaction) and `$transactionDate` come from
 *   `largestExpenseForCategoryMonth()`; `$frequency` is unused for this shape.
 *
 * `$amount` is deliberately one field for both cases — it is always "the money
 * amount attributable to `$merchantName`" — because the refund guard (design.md §6
 * rule 2) compares it against the observation's `spent` the same way regardless of
 * shape: when `$amount` exceeds `spent`, the composer omits the share rather than
 * print one above 100%.
 */
final class CoachingCause
{
    public function __construct(
        public readonly string $merchantName,
        public readonly float $amount,
        public readonly int $frequency,
        public readonly ?CarbonImmutable $transactionDate = null,
    ) {}
}
