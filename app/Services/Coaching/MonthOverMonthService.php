<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\MonthCursor;
use App\Models\User;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Carbon\CarbonImmutable;

/**
 * Answers "¿qué cambió desde el mes pasado?" for one user.
 *
 * Two reads of the same query over two windows, which is the whole implementation
 * and also the whole risk: the windows have to be comparable. Both come from one
 * {@see MonthCursor} so that neither can be built from a different idea of what
 * day it is, and the previous one is deliberately month-to-**date** rather than
 * the whole previous month. That difference is the bug this class is shaped to
 * avoid: comparing 16 days against 31 answers "gastaste menos" every single time
 * until the end of the month, with true figures and a false conclusion, and
 * nothing downstream could detect it.
 *
 * Like {@see DailyAllowanceService} it only ever answers a button — no `send()`,
 * no schedule, none of the machinery the unprompted voices carry to earn an
 * interruption.
 */
final class MonthOverMonthService
{
    public function __construct(
        private readonly TransactionRepositoryContract $transactions,
        private readonly MonthOverMonthComparator $comparator,
        private readonly MonthOverMonthComposer $composer,
    ) {}

    /** The answer, or null when the coaching subsystem is switched off. */
    public function composeOnDemand(User $user): ?string
    {
        if (! (bool) config('coaching.enabled')) {
            return null;
        }

        $cursor = MonthCursor::forInstant(CarbonImmutable::now((string) config('app.timezone')));

        $current = $this->transactions->expenseByCategoryBetween(
            $user->id,
            $cursor->startsAt,
            $cursor->monthToDateEndsAt(),
        );

        $previous = $this->transactions->expenseByCategoryBetween(
            $user->id,
            $cursor->previousMonthStartsAt(),
            $cursor->previousMonthToDateEndsAt(),
        );

        $shifts = $this->comparator->compare(
            $current,
            $previous,
            (float) config('coaching.shift_min_amount'),
            (int) config('coaching.shift_max_categories'),
        );

        // Los totales salen de TODAS las filas, no de los shifts. El titular es el
        // movimiento real del mes; las vinetas explican la mayor parte de el. Si el
        // titular se sumara desde las vinetas, el tope y el umbral lo volverian
        // mentira sin que nada lo muestre.
        return $this->composer->compose($shifts, $this->total($current), $this->total($previous), $cursor);
    }

    /** @param array<int, object{category_id: int|null, category_name: string|null, total: float}> $rows */
    private function total(array $rows): float
    {
        return array_sum(array_map(fn (object $row): float => (float) $row->total, $rows));
    }
}
