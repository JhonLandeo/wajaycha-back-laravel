<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class CategoryRepository implements CategoryRepositoryContract
{
    public function findById(int $id): ?Category
    {
        /** @var Category|null $category */
        $category = Category::query()->find($id);
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
                $query->where('name', 'ILIKE', '%' . $search . '%');
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
}
