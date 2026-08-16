<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceObservation;
use App\Enums\BudgetPeriod;
use App\Models\CoachingEvaluation;

/**
 * Writes down what the sweep looked at, so that silence becomes readable.
 *
 * The counterpart to {@see SpokenObservationLedger}, and deliberately a separate
 * class rather than another method on it. That one arbitrates whether the coach
 * MAY speak — its writes are on the critical path, a failed claim changes what
 * the user receives, and its unique index is a correctness guarantee. This one
 * only records; nothing downstream of it reads what it wrote, and a failure here
 * must never cost a message.
 *
 * The distinction shows up in the contract: {@see record()} returns nothing and
 * is safe to call before knowing whether anything will be said. It is called on
 * every sweep, including the ones that end in silence — those are the ones worth
 * recording.
 */
final class EvaluatedCategoryLedger
{
    /**
     * Records one verdict per snapshot, overwriting the month's previous verdict
     * for the same category.
     *
     * Last write wins, and that is the intended semantics rather than a
     * consequence of the upsert: a category clean on the 3rd that goes over
     * budget on the 18th has to end the month recorded as over budget. Appending
     * instead would keep both and make "was this month clean?" a question about
     * row ordering.
     *
     * `$observations` is expected to be the UNTRUNCATED evaluation — every
     * snapshot that produced a band, not the three the coach will actually say.
     * A category dropped by `max_observations_per_message` was still evaluated
     * and still crossed a line; recording it as `clean` because the message had
     * no room for it would write down a verdict the evaluator never reached.
     *
     * @param  CategoryMonthSnapshot[]  $snapshots  the month's evaluable universe
     * @param  PaceObservation[]  $observations  every band reached, untruncated
     */
    public function record(int $userId, MonthCursor $cursor, array $snapshots, array $observations): void
    {
        if ($snapshots === []) {
            return;
        }

        $bandByCategory = [];
        foreach ($observations as $observation) {
            $bandByCategory[$observation->categoryId] = $observation->band;
        }

        $now = now();
        $periodMonth = $cursor->periodMonth->startOfMonth()->toDateString();

        $rows = [];
        foreach ($snapshots as $snapshot) {
            $isEnvelope = $snapshot->budgetPeriod === BudgetPeriod::YEARLY;

            $rows[] = [
                'user_id' => $userId,
                'period_month' => $periodMonth,
                'category_id' => $snapshot->categoryId,
                'outcome' => $this->outcomeFor($snapshot, $bandByCategory),
                'budget_period' => $snapshot->budgetPeriod->value,
                'budget_amount' => $snapshot->monthlyBudget,
                // An envelope's band is decided against the year, so the figure
                // stored beside it has to be the year's too — otherwise the row
                // records a verdict and a number that disagree.
                'spent_amount' => $isEnvelope ? $snapshot->spentInYear : $snapshot->spent,
                'evaluated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Sin transaccion propia, a diferencia de SpokenObservationLedger::claim().
        // Aquel envuelve su insert en un savepoint porque ATRAPA la violacion del
        // indice unico, y en PostgreSQL una violacion atrapada deja inservible la
        // transaccion que la contiene. Aca no se atrapa nada: el upsert resuelve el
        // conflicto en la propia sentencia, y una sentencia ya es atomica. Envolverla
        // solo copiaria la forma de un problema que este metodo no tiene.
        CoachingEvaluation::query()->upsert(
            $rows,
            ['user_id', 'period_month', 'category_id'],
            ['outcome', 'budget_period', 'budget_amount', 'spent_amount', 'evaluated_at', 'updated_at'],
        );
    }

    /**
     * A budget of zero is `blind`, not `clean`: the evaluator refuses those
     * categories outright, so calling them clean would claim a verdict nobody
     * reached. `BlindnessDetector` reports the same condition to the user as one
     * aggregate sentence; here each category keeps its own row, because a streak
     * is asked per category.
     *
     * @param  array<int, string>  $bandByCategory
     */
    private function outcomeFor(CategoryMonthSnapshot $snapshot, array $bandByCategory): string
    {
        if ($snapshot->monthlyBudget <= 0.0) {
            return CoachingEvaluation::OUTCOME_BLIND;
        }

        return $bandByCategory[$snapshot->categoryId] ?? CoachingEvaluation::OUTCOME_CLEAN;
    }
}
