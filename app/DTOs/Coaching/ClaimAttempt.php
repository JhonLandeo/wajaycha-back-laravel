<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

use Carbon\CarbonImmutable;

/**
 * The full payload `SpokenObservationLedger::claim()` needs to attempt a claim.
 *
 * Task 4.0, decision 1: collapses `claim()`'s previous 11-parameter signature
 * into a single value object. `budgetAmount`, `spentAmount` and `projectedAmount`
 * are all `float|null` and sat adjacent in the old parameter list — PHP does not
 * stop a positional call from silently transposing them, and the only thing that
 * made phase 3's tests safe was that every call spread named arguments. That
 * convention had nothing concrete to enforce it against: `FinancialCoachingService`,
 * the one caller `claim()` will ever have, is phase 4.7 and does not exist yet in
 * this batch, so "document named arguments are mandatory and enforce it at the
 * call site" cannot be honoured — there is no call site.
 *
 * A value object is chosen instead: it gives the payload one construction shape
 * rather than eleven loose parameters restated at every call site, and every read
 * after construction goes through a named property (`->budgetAmount`), never a
 * positional slot. It is also additive for the blindness case `SpokenObservationLedger`
 * already supports (`categoryId: null`, `band: 'blind'`) without a second `claim()`
 * overload.
 */
final class ClaimAttempt
{
    public function __construct(
        public readonly int $userId,
        public readonly CarbonImmutable $periodMonth,
        public readonly string $subjectKey,
        public readonly string $band,
        public readonly ?int $categoryId,
        public readonly bool $isLumpy,
        public readonly float $budgetAmount,
        public readonly float $spentAmount,
        public readonly ?float $projectedAmount,
        public readonly int $dayOfMonth,
        public readonly string $entryPoint,
    ) {}
}
