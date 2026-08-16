<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\DailyAllowance;
use App\DTOs\Coaching\MonthCursor;
use App\Enums\BudgetPeriod;

/**
 * Turns budgets into the only figure that answers "¿cuánto puedo gastar hoy?":
 * what is left, over the days that are actually left.
 *
 * A separate decider from {@see PaceEvaluator} rather than another band in it,
 * and the reason is which categories each one is allowed to see. The evaluator
 * speaks only about categories that crossed something, because it feeds a voice
 * that has to earn every interruption. This question is the inverse: the
 * categories where nothing went wrong are precisely the ones with room, and they
 * are the answer. Widening the evaluator to emit them would put "nothing is
 * wrong here" observations into a ledger built to make sure each one is said at
 * most once.
 *
 * Pure, like the evaluator, and for the same reason `BoundariesTest` enforces it:
 * a decision that cannot reach a database can be asserted against a fixed set of
 * figures, which is what makes the arithmetic below reviewable at all.
 */
final class DailyAllowanceCalculator
{
    /**
     * @param  CategoryMonthSnapshot[]  $snapshots
     * @return DailyAllowance[] ordered by daily room, most first; the categories
     *                          with none left land at the end.
     */
    public function calculate(array $snapshots, MonthCursor $cursor): array
    {
        $daysLeft = $cursor->daysLeft();

        $allowances = [];

        foreach ($snapshots as $snapshot) {
            if (! $this->isDivisible($snapshot)) {
                continue;
            }

            $remaining = $snapshot->monthlyBudget - $snapshot->spent;

            $allowances[] = new DailyAllowance(
                categoryId: $snapshot->categoryId,
                name: $snapshot->name,
                budget: $snapshot->monthlyBudget,
                spent: $snapshot->spent,
                remaining: $remaining,
                perDay: $remaining > 0.0 ? $remaining / $daysLeft : 0.0,
            );
        }

        // Mas margen primero. Es el orden en que la respuesta se usa: quien
        // pregunta cuanto puede gastar esta por decidir en que, y las categorias
        // sin margen —que quedan al final por tener perDay cero— no son una
        // opcion sino una advertencia.
        usort($allowances, fn (DailyAllowance $a, DailyAllowance $b): int => $b->perDay <=> $a->perDay);

        return $allowances;
    }

    /**
     * Whether this category's budget can be divided into days at all.
     *
     * Three exclusions, each a different kind of "there is no daily figure here":
     *
     * - **Not an expense.** Income has no budget to spend down. The evaluator
     *   refuses these itself rather than trusting an upstream filter, and this
     *   class inherits the same distrust for the same reason.
     * - **No budget.** Nothing to divide. That a category is unbudgeted is a real
     *   finding, but it is blindness — a different question, already owned by
     *   {@see BlindnessDetector} — and answering it here would mix "no sabemos" in
     *   among figures the reader is about to act on.
     * - **A yearly envelope.** The exclusion that is a judgement rather than an
     *   arithmetic impossibility, so it is the one worth defending: an envelope is
     *   consumed in jumps — a consultation, a premium, a registration — and
     *   spreading an annual amount over the days left in *this month* invents a
     *   rhythm the spending does not have. It would also read against the wrong
     *   denominator: `spent` here is month-to-date, while an envelope is measured
     *   against the year. The message says "presupuestos mensuales" out loud so
     *   the omission is stated rather than silent.
     */
    private function isDivisible(CategoryMonthSnapshot $snapshot): bool
    {
        return $snapshot->type === 'expense'
            && $snapshot->budgetPeriod === BudgetPeriod::MONTHLY
            && $snapshot->monthlyBudget > 0.0;
    }
}
