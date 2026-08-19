<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\Exceptions\Coaching\TooManyCategoriesException;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryRepositoryContract
{
    /**
     * Find a Category belonging to $userId, or null.
     *
     * $userId is an AUTHORIZATION BOUNDARY, not a convenience filter. Resolving by id
     * alone returns another user's record, which is how every endpoint in this API came
     * to be exploitable. A caller that cannot supply the owner has no business calling
     * this method.
     */
    public function findById(int $id, int $userId): ?Category;

    public function findByUserId(int $userId): Collection;

    public function getMonthlyReport(int $userId, int $month, int $year, int $page, int $perPage, ?string $search = null): LengthAwarePaginator;

    public function getAllForUser(int $userId, ?string $search = null): Collection;

    public function delete(Category $category): bool;

    public function update(Category $category, array $data): bool;

    public function create(array $data): Category;

    /**
     * Put a category in a Pareto band, replacing whatever band it was in.
     *
     * `category_pareto_assignments` has no model, so it was written with raw
     * `DB::table()` calls from two actions and an observer — three copies of the same
     * column list, drifting independently. `ParetoRepository` joins this table to
     * answer which categories fall in which band, which is the reading the product is
     * built around, so a stale or missing row moves a category into the wrong band
     * without raising anything.
     *
     * One category holds one band: the write is an upsert on `category_id`, not an
     * insert, and calling it twice does not accumulate rows.
     */
    public function assignParetoClassification(int $categoryId, int $paretoClassificationId): void;

    /**
     * Take a category out of every Pareto band.
     */
    public function clearParetoClassification(int $categoryId): void;

    /**
     * The Pareto band a category is in right now, or null.
     *
     * The read half of {@see assignParetoClassification()}, and it exists because the
     * write half had no counterpart. `categories.pareto_classification_id` was dropped
     * when the link moved to `category_pareto_assignments`, so a plain `Category` no
     * longer carries the band anywhere — `GET /api/categories/{id}` answered without
     * the field, the SPA's edit form opened with the Pareto select empty, and its own
     * validation then refused to save an expense until the user re-picked a band the
     * system already knew.
     */
    public function paretoClassificationIdFor(int $categoryId): ?int;

    /**
     * Leaf **expense** categories with spend this month, shaped for `PaceEvaluator`
     * (design.md D2, §5.1). Wraps `get_monthly_category_budget_report`, the same
     * function the SPA reads, so `spent` never disagrees with the report.
     *
     * Categories with `monthly_budget = 0` ARE included when they have spend —
     * phase 4's blindness detection depends on them; the evaluator ignores them,
     * this repository must not filter them out. Categories with `spent <= 0` are
     * skipped (design.md D2 item 3): a budgeted category with no spend has nothing
     * to project, and an unbudgeted category with no spend is not blindness either.
     *
     * @return CategoryMonthSnapshot[]
     *
     * @throws TooManyCategoriesException when the function's own `total_records`
     *                                    exceeds `config('coaching.max_categories')` — coaching a truncated
     *                                    category list would produce confidently wrong silence, never a
     *                                    silent truncation.
     */
    public function expenseBudgetSnapshotsForMonth(int $userId, int $month, int $year): array;

    /**
     * Leaf **expense** categories that HAVE a budget, whether or not they were
     * spent on this month.
     *
     * The complement of {@see expenseBudgetSnapshotsForMonth()}, and the two
     * filters are opposites on purpose: that one keeps categories with spend and
     * lets an unbudgeted one through, because it feeds pace and blindness. This
     * one keeps categories with a budget and lets an untouched one through,
     * because it feeds the daily allowance — and a budgeted category nobody has
     * spent on yet is not an edge case there, it is the category with the most
     * room left. Dropping it would understate what the user can spend, which is
     * the one direction this figure must never be wrong in.
     *
     * `spent` comes from `get_monthly_category_budget_report`, the same function
     * and the same argument order as the other two callers, so no reading of this
     * repository can disagree with the report the SPA shows.
     *
     * `largestExpenseAmount` is left at zero and `spentInYear` unfetched: both
     * exist for pace decisions this question does not make, and querying for them
     * would be two round trips bought for figures nobody reads.
     *
     * @return CategoryMonthSnapshot[]
     *
     * @throws TooManyCategoriesException on the same cap, for the same reason: a
     *                                    truncated category list produces a daily allowance that is
     *                                    confidently too small, with nothing to show it was truncated.
     */
    public function budgetedExpenseSnapshotsForMonth(int $userId, int $month, int $year): array;

    /**
     * Budget-versus-spend rows for a month, as the monthly summary needs them.
     *
     * The third caller of `get_monthly_category_budget_report`, and the last one that
     * was still running the query from outside a repository. It returns figures only —
     * the variance and the over/under verdict are decided by `FinancialReportService`,
     * because a stored procedure can return a number but not what the number means.
     *
     * Kept separate from `expenseBudgetSnapshotsForMonth()` rather than merged with it:
     * that one caps the row count for coaching and throws past the cap, which is a rule
     * the monthly summary must not inherit.
     *
     * @return array<int, \stdClass>
     */
    public function budgetDeviationRowsForMonth(int $userId, int $month, int $year): array;
}
