<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ReconciliationCandidate;
use App\Models\Transaction;

interface ReconciliationCandidateRepositoryContract
{
    /**
     * The row most likely to be the same real movement as `$transaction`, or null.
     *
     * "Most likely" is nearest in time among the rows that qualify. The rule for
     * what qualifies belongs to the caller; what lives here is how to ask it of
     * PostgreSQL.
     */
    public function closestUnpairedMatch(Transaction $transaction, int $windowHours): ?Transaction;

    /**
     * Records the finding, or null if the pair was claimed concurrently.
     *
     * `$resolution` overrides the pending defaults for a pair the system settled
     * on its own — status, who decided, and when.
     *
     * @param  array<string, mixed>  $resolution
     */
    public function open(Transaction $transaction, Transaction $candidate, array $resolution = []): ?ReconciliationCandidate;

    /**
     * Pairs the system merged on its own and the user has not reviewed, newest
     * first — the list that keeps an automatic decision from being a silent one.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ReconciliationCandidate>
     */
    public function autoMergedFor(int $userId, int $limit): mixed;
}
