<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\Enums\BudgetPeriod;
use App\Exceptions\Coaching\TooManyCategoriesException;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class CategoryRepository implements CategoryRepositoryContract
{
    public function findById(int $id, int $userId): ?Category
    {
        /** @var Category|null $category */
        $category = Category::query()->whereKey($id)->where('user_id', $userId)->first();

        return $category;
    }

    public function findByUserId(int $userId): Collection
    {
        return Category::query()
            ->where('user_id', $userId)
            ->get();
    }

    public function getMonthlyReport(int $userId, int $month, int $year, int $page, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        // Rule 02 Violation Fix: Explicit columns instead of SELECT *
        //
        // `budget_period` arrives through a join placed AROUND the function, never
        // by editing it. `get_monthly_category_budget_report` carries Financial
        // Analysis rules that CLAUDE.md forbids extending, and the client needs
        // this column for a reason the function has no part in: labelling S/ 1200
        // as annual so nobody reads it as a monthly figure.
        //
        // LEFT JOIN, not INNER: the report's row count and its `total_records`
        // must not depend on this join. An inner join would silently drop a row —
        // and shift the pagination — if a category disappeared between the
        // function's read and this one.
        $results = DB::select(
            'SELECT r.id, r.name, r.monthly_budget, r.spent, r.available_budget, r.percentage_spent,
                    r.rule_quantity, r.total_records, c.budget_period
             FROM get_monthly_category_budget_report(?, ?, ?, ?, ?, ?) r
             LEFT JOIN categories c ON c.id = r.id',
            [$page, $perPage, $userId, $month, $year, $search]
        );

        $total = empty($results) ? 0 : (int) $results[0]->total_records;

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function getAllForUser(int $userId, ?string $search = null): Collection
    {
        // Rule 02 Violation Fix (Eager Loading): Using with() and withCount()
        return Category::query()
            ->where('user_id', $userId)
            ->when($search, function ($query, $search) {
                $query->where('name', 'ILIKE', '%'.$search.'%');
            })
            ->where(function ($query) {
                $query->whereNotNull('parent_id')
                    ->orWhereDoesntHave('children');
            })
            ->withCount('categorizationRules')
            ->orderBy('categorization_rules_count', 'desc')
            ->get();
    }

    public function delete(Category $category): bool
    {
        return (bool) $category->delete();
    }

    public function update(Category $category, array $data): bool
    {
        return $category->update($data);
    }

    public function create(array $data): Category
    {
        /** @var Category */
        return Category::query()->create($data);
    }

    public function assignParetoClassification(int $categoryId, int $paretoClassificationId): void
    {
        // An upsert, not an insert: a category holds one band, and the create path used
        // to insert blindly while the update path upserted. Same table, two behaviours.
        DB::table('category_pareto_assignments')->updateOrInsert(
            ['category_id' => $categoryId],
            [
                'pareto_classification_id' => $paretoClassificationId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function clearParetoClassification(int $categoryId): void
    {
        DB::table('category_pareto_assignments')
            ->where('category_id', $categoryId)
            ->delete();
    }

    /**
     * @return array<int, \stdClass>
     */
    public function budgetDeviationRowsForMonth(int $userId, int $month, int $year): array
    {
        // Moved verbatim from FinancialReportService, argument order included. The
        // function takes (p_page, p_per_page, p_user_id, p_month, p_year, p_search).
        /** @var array<int, \stdClass> */
        return DB::select(
            'SELECT id, name, monthly_budget AS budgeted, spent, available_budget, percentage_spent
             FROM get_monthly_category_budget_report(?, ?, ?, ?, ?, ?)',
            [1, 100, $userId, $month, $year, null]
        );
    }

    public function expenseBudgetSnapshotsForMonth(int $userId, int $month, int $year): array
    {
        $maxCategories = (int) config('coaching.max_categories');

        // Rule 02 Violation Fix: Explicit columns instead of SELECT *
        $rows = DB::select(
            'SELECT id, name, monthly_budget, spent, available_budget, percentage_spent, rule_quantity, total_records
             FROM get_monthly_category_budget_report(?, ?, ?, ?, ?, ?)',
            [1, $maxCategories, $userId, $month, $year, null]
        );

        $totalRecords = empty($rows) ? 0 : (int) $rows[0]->total_records;

        if ($totalRecords > $maxCategories) {
            throw TooManyCategoriesException::forUser($userId, $totalRecords, $maxCategories);
        }

        // The function returns neither a `type` nor a `budget_period` column
        // (design.md D2 item 2). One query carries both: the keys are the expense
        // category ids the intersection needs, the values are the budget unit the
        // evaluator needs.
        /** @var array<int, string> $periodByCategory */
        $periodByCategory = Category::query()
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->pluck('budget_period', 'id')
            ->all();

        $survivingRows = array_values(array_filter(
            $rows,
            fn (object $row): bool => isset($periodByCategory[(int) $row->id]) && (float) $row->spent > 0.0
        ));

        if ($survivingRows === []) {
            return [];
        }

        $survivingIds = array_map(fn (object $row): int => (int) $row->id, $survivingRows);
        $largestExpenseByCategory = $this->largestExpenseByCategory($userId, $survivingIds, $month, $year);

        // Only yearly categories pay for the year-to-date query, and only when at
        // least one of them survived. A workspace with no envelope budgets — which
        // is every workspace until the owner marks one — issues exactly the queries
        // it issued before this column existed.
        $yearlyIds = array_values(array_filter(
            $survivingIds,
            fn (int $id): bool => BudgetPeriod::fromColumn($periodByCategory[$id] ?? null) === BudgetPeriod::YEARLY
        ));
        $spentInYearByCategory = $yearlyIds === []
            ? []
            : $this->spentInYearByCategory($userId, $yearlyIds, $year);

        return array_map(
            fn (object $row): CategoryMonthSnapshot => new CategoryMonthSnapshot(
                categoryId: (int) $row->id,
                name: (string) $row->name,
                type: 'expense',
                monthlyBudget: (float) $row->monthly_budget,
                spent: (float) $row->spent,
                largestExpenseAmount: $largestExpenseByCategory[(int) $row->id] ?? 0.0,
                budgetPeriod: BudgetPeriod::fromColumn($periodByCategory[(int) $row->id] ?? null),
                spentInYear: $spentInYearByCategory[(int) $row->id] ?? 0.0,
            ),
            $survivingRows
        );
    }

    /**
     * Year-to-date expense total per category, for yearly budgets only.
     *
     * Reads `v_unified_transactions` — the same source `spent` and
     * `largestExpenseByCategory()` come from. A yearly envelope is compared
     * against this figure and a monthly budget against `spent`, and if the two
     * came from different sources the coach could report a category as both
     * within its envelope and over its month using numbers that never agreed.
     *
     * The window is the calendar year in the reference timezone. That is a
     * simplification the owner can revisit: a budget "for the year" could
     * reasonably mean a rolling twelve months, but a calendar year is what a
     * person means when they set one, and it is the only reading that makes two
     * users' Januaries comparable.
     *
     * @param  int[]  $categoryIds
     * @return array<int, float> keyed by category_id
     */
    private function spentInYearByCategory(int $userId, array $categoryIds, int $year): array
    {
        $timezone = (string) config('app.timezone');
        $startsAt = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $timezone);
        $endsAt = $startsAt->addYear();

        $rows = DB::table('v_unified_transactions')
            ->select('category_id', DB::raw('SUM(amount) as spent_in_year'))
            ->where('user_id', $userId)
            ->where('type_transaction', 'expense')
            ->whereIn('category_id', $categoryIds)
            ->where('date_operation', '>=', $startsAt)
            ->where('date_operation', '<', $endsAt)
            ->groupBy('category_id')
            ->get();

        $spentByCategory = [];
        foreach ($rows as $row) {
            $spentByCategory[(int) $row->category_id] = (float) $row->spent_in_year;
        }

        return $spentByCategory;
    }

    /**
     * Cheapest per-category "largest single expense" scalar, computed alongside
     * the budget snapshot so `PaceEvaluator` can decide lumpiness (design.md
     * §5.1 order 3) without waiting for `TransactionRepository::
     * largestExpenseForCategoryMonth()`, which only runs post-evaluation for
     * message composition (design.md D5).
     *
     * Reads `v_unified_transactions` — the same source `spent` comes from — never
     * the raw `transactions` table, and never `get_transactions_by_detail()`
     * (design.md D5: its `HAVING COUNT(t.id) > 1` drops single-transaction
     * merchants, exactly the lumpy case this scalar exists to detect).
     *
     * @param  int[]  $categoryIds
     * @return array<int, float> keyed by category_id
     */
    private function largestExpenseByCategory(int $userId, array $categoryIds, int $month, int $year): array
    {
        $timezone = (string) config('app.timezone');
        $startsAt = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone);
        $endsAt = $startsAt->addMonthNoOverflow();

        // Rule 02 Violation Fix: Explicit columns instead of SELECT *
        $rows = DB::table('v_unified_transactions')
            ->select('category_id', DB::raw('MAX(amount) as largest_expense'))
            ->where('user_id', $userId)
            ->where('type_transaction', 'expense')
            ->whereIn('category_id', $categoryIds)
            ->where('date_operation', '>=', $startsAt)
            ->where('date_operation', '<', $endsAt)
            ->groupBy('category_id')
            ->get();

        $largestByCategory = [];
        foreach ($rows as $row) {
            $largestByCategory[(int) $row->category_id] = (float) $row->largest_expense;
        }

        return $largestByCategory;
    }
}
