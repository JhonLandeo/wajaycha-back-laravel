<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Category;
use App\Models\ParetoClassification;
use App\Repositories\Contracts\ParetoRepositoryContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ParetoRepository implements ParetoRepositoryContract
{
    public function findById(int $id): ?ParetoClassification
    {
        /** @var ParetoClassification|null */
        return ParetoClassification::query()->find($id);
    }

    public function getAllForUser(int $userId): Collection
    {
        return ParetoClassification::query()
            ->where('user_id', $userId)
            ->get();
    }

    public function getMonthlyReport(int $userId, ?int $month, ?int $year, int $page, int $perPage): LengthAwarePaginator
    {
        // Rule 02 Violation Fix: Explicit columns
        $columns = 'id, name, percentage, actual_percentage, monthly_budget, spent, available_budget, percentage_spent, categories, total_income, total_expense, total_records';
        
        $results = DB::select("SELECT $columns FROM get_pareto_monthly_report(?, ?, ?, ?, ?)", [
            $userId,
            $month,
            $year,
            $page,
            $perPage
        ]);

        foreach ($results as $row) {
            /** @var string $cats */
            $cats = $row->categories;
            $row->categories = json_decode($cats);
        }

        $total = empty($results) ? 0 : (int) $results[0]->total_records;

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function getCategories(int $paretoId): Collection
    {
        return Category::query()
            ->select('categories.*')
            ->join('category_pareto_assignments', 'categories.id', '=', 'category_pareto_assignments.category_id')
            ->where('category_pareto_assignments.pareto_classification_id', $paretoId)
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
