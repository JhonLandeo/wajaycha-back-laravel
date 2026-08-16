<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\MonthCursor;
use App\Models\User;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Carbon\CarbonImmutable;

/**
 * Answers "¿qué es fijo y qué decido yo?" for one user.
 *
 * Reads one complete month at a time instead of grouping the whole window in SQL,
 * and the reason is worth stating because the single-query version is the obvious
 * one. `date_operation` is a `timestamptz`, so a `date_trunc('month', …)` cuts the
 * series on whatever timezone the session happens to carry — five hours off Lima,
 * silently, moving every month boundary and every figure derived from it. The
 * month boundaries are already built correctly in PHP by {@see MonthCursor} and
 * already exercised by the tests around it, so this reuses them and pays a handful
 * of small aggregates for a whole class of bug it never has to think about again.
 *
 * The current month is excluded by `completedMonthStarts()`. A partial month
 * among whole ones would show up as variation and call a steady rent volatile.
 *
 * Like the other menu answers it only ever replies to a button: no `send()`, no
 * schedule.
 */
final class SpendingRhythmService
{
    public function __construct(
        private readonly TransactionRepositoryContract $transactions,
        private readonly SpendingRhythmClassifier $classifier,
        private readonly SpendingRhythmComposer $composer,
    ) {}

    /** The answer, or null when the coaching subsystem is switched off. */
    public function composeOnDemand(User $user): ?string
    {
        if (! (bool) config('coaching.enabled')) {
            return null;
        }

        $cursor = MonthCursor::forInstant(CarbonImmutable::now((string) config('app.timezone')));
        $months = (int) config('coaching.rhythm_months');

        $series = array_map(
            fn (CarbonImmutable $start): array => $this->transactions->expenseByCategoryBetween(
                $user->id,
                $start,
                $start->addMonthNoOverflow(),
            ),
            $cursor->completedMonthStarts($months),
        );

        $habits = $this->classifier->classify(
            $series,
            (float) config('coaching.rhythm_variation_threshold'),
            (int) config('coaching.rhythm_min_months'),
        );

        return $this->composer->compose($habits, $months, (int) config('coaching.rhythm_max_categories'));
    }
}
