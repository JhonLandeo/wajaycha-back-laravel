<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Pareto\BudgetedCategoryRow;
use App\DTOs\Pareto\ParetoBandRow;
use App\DTOs\Pareto\ParetoWindowTotals;
use App\Enums\BudgetPeriod;
use App\Models\Category;
use App\Models\ParetoClassification;
use App\Repositories\Contracts\ParetoRepositoryContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ParetoRepository implements ParetoRepositoryContract
{
    public function findById(int $id, int $userId): ?ParetoClassification
    {
        /** @var ParetoClassification|null */
        return ParetoClassification::query()->whereKey($id)->where('user_id', $userId)->first();
    }

    public function getAllForUser(int $userId): Collection
    {
        return ParetoClassification::query()
            ->where('user_id', $userId)
            ->get();
    }

    public function bandRowsForUser(int $userId): array
    {
        return ParetoClassification::query()
            ->select(['id', 'name', 'percentage'])
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(static fn (ParetoClassification $band): ParetoBandRow => new ParetoBandRow(
                id: (int) $band->id,
                name: (string) $band->name,
                percentage: (float) $band->percentage,
            ))
            ->all();
    }

    public function budgetedLeafCategories(int $userId): array
    {
        $rows = DB::table('categories as c')
            ->select([
                'c.id',
                'c.name',
                'c.type',
                'c.monthly_budget',
                'c.budget_period',
                'cpa.pareto_classification_id',
            ])
            ->leftJoin('category_pareto_assignments as cpa', 'cpa.category_id', '=', 'c.id')
            ->where('c.user_id', $userId)
            ->where('c.type', '!=', 'income')
            ->where(function (Builder $query): void {
                $query->whereNotNull('c.parent_id')
                    ->orWhereNotExists(function (Builder $child): void {
                        $child->select(DB::raw(1))
                            ->from('categories as c2')
                            ->whereColumn('c2.parent_id', 'c.id');
                    });
            })
            ->get();

        return $rows->map(static fn (object $row): BudgetedCategoryRow => new BudgetedCategoryRow(
            id: (int) $row->id,
            name: (string) $row->name,
            type: (string) $row->type,
            monthlyBudget: (float) $row->monthly_budget,
            budgetPeriod: BudgetPeriod::fromColumn($row->budget_period),
            bandId: $row->pareto_classification_id !== null ? (int) $row->pareto_classification_id : null,
        ))->all();
    }

    public function netSpendByCategory(int $userId, ?int $month, ?int $year): array
    {
        $rows = $this->windowQuery($userId, $month, $year)
            ->select('category_id')
            ->selectRaw("SUM(CASE WHEN type_transaction = 'expense' THEN amount WHEN type_transaction = 'income' THEN -amount ELSE 0 END) AS net")
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->get();

        $spend = [];
        foreach ($rows as $row) {
            $spend[(int) $row->category_id] = (float) $row->net;
        }

        return $spend;
    }

    public function windowTotals(int $userId, ?int $month, ?int $year): ParetoWindowTotals
    {
        $row = $this->windowQuery($userId, $month, $year)
            ->selectRaw("COALESCE(SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END), 0) AS income")
            ->selectRaw("COALESCE(SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END), 0) AS expense")
            ->first();

        return new ParetoWindowTotals(
            income: (float) ($row->income ?? 0),
            expense: (float) ($row->expense ?? 0),
        );
    }

    public function monthsWithActivity(int $userId): int
    {
        $count = DB::table('v_unified_transactions')
            ->where('user_id', $userId)
            ->distinct()
            ->count(DB::raw("DATE_TRUNC('month', date_operation)"));

        return max((int) $count, 1);
    }

    /**
     * The user's movements narrowed to a window, as a half-open date RANGE.
     *
     * `EXTRACT(YEAR FROM date_operation) = ?` reads better and cannot use an index —
     * wrapping the column in a function discards it. The repository rules require a
     * sargable predicate, and the PostgreSQL function this replaced did not have one.
     */
    private function windowQuery(int $userId, ?int $month, ?int $year): Builder
    {
        $query = DB::table('v_unified_transactions')->where('user_id', $userId);

        if ($year === null) {
            // A month with no year means "this month across every year", which has no
            // range form and so no sargable one. Kept because the endpoint has always
            // accepted the combination; narrowing it here would be a silent change to
            // an answer somebody may be reading.
            return $month !== null
                ? $query->whereRaw('EXTRACT(MONTH FROM date_operation) = ?', [$month])
                : $query;
        }

        $from = $month !== null
            ? Carbon::create($year, $month, 1)->startOfDay()
            : Carbon::create($year, 1, 1)->startOfDay();

        $to = $month !== null ? $from->copy()->addMonth() : $from->copy()->addYear();

        return $query->where('date_operation', '>=', $from)->where('date_operation', '<', $to);
    }

    public function getCategories(int $paretoId, int $userId, ?string $search = null): Collection
    {
        return Category::query()
            ->select('categories.*')
            ->join('category_pareto_assignments', 'categories.id', '=', 'category_pareto_assignments.category_id')
            ->where('category_pareto_assignments.pareto_classification_id', $paretoId)
            ->where('categories.user_id', $userId)
            ->when($search, function ($query, $search) {
                $query->where('categories.name', 'ILIKE', '%' . $search . '%');
            })
            ->withCount('categorizationRules')
            ->orderBy('categorization_rules_count', 'desc')
            ->get();
    }

    public function create(array $data): ParetoClassification
    {
        /** @var ParetoClassification */
        return ParetoClassification::query()->create($data);
    }

    public function update(ParetoClassification $pareto, array $data): bool
    {
        return $pareto->update($data);
    }

    public function delete(ParetoClassification $pareto): bool
    {
        return (bool) $pareto->delete();
    }
}
