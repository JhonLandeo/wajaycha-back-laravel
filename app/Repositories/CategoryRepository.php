<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Coaching\CategoryMonthSnapshot;
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
        $results = DB::select(
            'SELECT id, name, monthly_budget, spent, available_budget, percentage_spent, rule_quantity, total_records 
             FROM get_monthly_category_budget_report(?, ?, ?, ?, ?, ?)',
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

        // The function returns no `type` column (design.md D2 item 2). Intersect
        // with expense category ids so income/transfer never reach the evaluator.
        $expenseCategoryIds = Category::query()
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->pluck('id')
            ->all();
        $expenseCategoryIds = array_flip($expenseCategoryIds);

        $survivingRows = array_values(array_filter(
            $rows,
            fn (object $row): bool => isset($expenseCategoryIds[$row->id]) && (float) $row->spent > 0.0
        ));

        if ($survivingRows === []) {
            return [];
        }

        $survivingIds = array_map(fn (object $row): int => (int) $row->id, $survivingRows);
        $largestExpenseByCategory = $this->largestExpenseByCategory($userId, $survivingIds, $month, $year);

        return array_map(
            fn (object $row): CategoryMonthSnapshot => new CategoryMonthSnapshot(
                categoryId: (int) $row->id,
                name: (string) $row->name,
                type: 'expense',
                monthlyBudget: (float) $row->monthly_budget,
                spent: (float) $row->spent,
                largestExpenseAmount: $largestExpenseByCategory[(int) $row->id] ?? 0.0,
            ),
            $survivingRows
        );
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
