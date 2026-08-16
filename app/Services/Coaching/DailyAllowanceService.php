<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\MonthCursor;
use App\Models\User;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Carbon\CarbonImmutable;

/**
 * Answers "¿cuánto puedo gastar hoy?" for one user.
 *
 * A service of its own rather than another method on {@see BudgetDigestService},
 * which would have been three lines shorter and wrong for the reason that class's
 * own docblock already gives: a service holds one contract, and a caller has to be
 * able to tell from the type what it is about to do. The digest is a status board
 * of what is going badly; this is a spending figure for the rest of the month.
 * They read the same snapshots and answer opposite questions.
 *
 * **There is no `send()` here, and there will not be one.** Every other voice in
 * this subsystem can start a conversation, which is why each of them carries a
 * switch, a ledger or a band ladder to earn it. This one only ever answers a
 * button. Adding a scheduled send would make a daily unprompted message out of a
 * class with none of that machinery.
 */
final class DailyAllowanceService
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly DailyAllowanceCalculator $calculator,
        private readonly DailyAllowanceComposer $composer,
    ) {}

    /**
     * The answer, or null when the coaching subsystem is switched off.
     *
     * Null means exactly one thing here, unlike in {@see BudgetDigestService},
     * where it also covers "nothing to report". The composer never returns null,
     * so "no tenés presupuestos" arrives as the sentence it is. That leaves the
     * caller able to say "no pude revisar" for the kill switch without ever
     * confusing it with "no hay nada que revisar" — a distinction the whole
     * `coaching_evaluations` table exists to preserve elsewhere.
     */
    public function composeOnDemand(User $user): ?string
    {
        if (! (bool) config('coaching.enabled')) {
            return null;
        }

        $cursor = MonthCursor::forInstant(CarbonImmutable::now((string) config('app.timezone')));

        $snapshots = $this->categories->budgetedExpenseSnapshotsForMonth(
            $user->id,
            $cursor->periodMonth->month,
            $cursor->periodMonth->year,
        );

        return $this->composer->compose($this->calculator->calculate($snapshots, $cursor), $cursor);
    }
}
