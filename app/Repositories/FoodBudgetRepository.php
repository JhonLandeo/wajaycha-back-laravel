<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Shopping\FoodBudgetCategory;
use App\DTOs\Shopping\FoodBudgetSnapshot;
use App\Models\Category;
use App\Models\FoodBudget;
use App\Repositories\Contracts\CategoryRepositoryContract;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FoodBudgetRepository implements FoodBudgetRepositoryContract
{
    public function __construct(
        private readonly CategoryRepositoryContract $categoryRepository,
        private readonly TransactionRepositoryContract $transactionRepository,
    ) {}

    public function findForUser(int $userId): ?FoodBudget
    {
        /** @var FoodBudget|null $budget */
        $budget = FoodBudget::query()
            ->where('user_id', $userId)
            ->with('categories')
            ->first();

        return $budget;
    }

    public function save(int $userId, array $categoryIds, float $monthlyAmount, int $shoppingDay): FoodBudget
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));

        return DB::transaction(function () use ($userId, $categoryIds, $monthlyAmount, $shoppingDay): FoodBudget {
            /** @var FoodBudget $budget */
            $budget = FoodBudget::query()->updateOrCreate(
                ['user_id' => $userId],
                [
                    'monthly_amount' => $monthlyAmount,
                    'shopping_day' => $shoppingDay,
                ]
            );

            $budget->categories()->syncWithPivotValues($categoryIds, ['user_id' => $userId]);

            return $budget->load('categories');
        });
    }

    public function snapshotFor(int $userId, CarbonImmutable $asOf): ?FoodBudgetSnapshot
    {
        $budget = $this->findForUser($userId);

        if ($budget === null || $budget->categories->isEmpty()) {
            return null;
        }

        $allCategories = $this->categoryRepository->findByUserId($userId);
        $byId = $allCategories->keyBy('id');
        $childrenByParent = $this->childrenByParent($allCategories);

        $selectedIds = $budget->categories
            ->map(static fn (Category $category): int => (int) $category->id)
            ->unique()
            ->values()
            ->all();

        $topLevel = $this->topLevelSelected($selectedIds, $byId);
        $scopeIds = [];

        foreach ($selectedIds as $selectedId) {
            foreach ($this->descendantIds($selectedId, $childrenByParent) as $scopeId) {
                $scopeIds[$scopeId] = true;
            }
        }

        $local = $asOf->setTimezone('America/Lima');
        [$monthStart, $monthEnd] = [$local->startOfMonth(), $local->startOfMonth()->addMonthNoOverflow()];
        $totals = $this->transactionRepository->expenseByCategoryBetween($userId, $monthStart, $monthEnd);

        $spent = 0.0;
        foreach ($totals as $row) {
            if ($row->category_id !== null && isset($scopeIds[(int) $row->category_id])) {
                $spent += (float) $row->total;
            }
        }

        $monthlyAmount = (float) $budget->monthly_amount;
        $available = max(0.0, round($monthlyAmount - $spent, 2));
        $shoppingDaysRemaining = $this->shoppingDaysRemaining($local, (int) $budget->shopping_day);
        $weeklyTarget = $shoppingDaysRemaining > 0
            ? round($available / $shoppingDaysRemaining, 2)
            : null;

        $categoryDtos = $topLevel
            ->map(static fn (Category $category): FoodBudgetCategory => new FoodBudgetCategory(
                id: (int) $category->id,
                name: $category->name,
                parentId: $category->parent_id === null ? null : (int) $category->parent_id,
                monthlyBudget: (float) $category->monthly_budget,
            ))
            ->values()
            ->all();

        $first = $topLevel->first();
        $categoryName = $first === null
            ? null
            : ($topLevel->count() > 1 ? "{$first->name} (+".($topLevel->count() - 1).')' : $first->name);

        return new FoodBudgetSnapshot(
            monthlyAmount: $monthlyAmount,
            spent: round($spent, 2),
            available: $available,
            isExceeded: $spent > $monthlyAmount,
            overspend: round(max(0.0, $spent - $monthlyAmount), 2),
            shoppingDay: (int) $budget->shopping_day,
            shoppingDaysRemaining: $shoppingDaysRemaining,
            weeklyTarget: $weeklyTarget,
            suggestedAmount: $this->suggestedAmount($topLevel, $byId, $childrenByParent),
            categories: $categoryDtos,
            primaryCategoryId: $first === null ? null : (int) $first->id,
            categoryName: $categoryName,
        );
    }

    public function spentBetween(int $userId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?float
    {
        $budget = $this->findForUser($userId);

        if ($budget === null || $budget->categories->isEmpty()) {
            return null;
        }

        $allCategories = $this->categoryRepository->findByUserId($userId);
        $childrenByParent = $this->childrenByParent($allCategories);
        $selectedIds = $budget->categories
            ->map(static fn (Category $category): int => (int) $category->id)
            ->unique()
            ->values()
            ->all();

        $scopeIds = [];
        foreach ($selectedIds as $selectedId) {
            foreach ($this->descendantIds($selectedId, $childrenByParent) as $scopeId) {
                $scopeIds[$scopeId] = true;
            }
        }

        $totals = $this->transactionRepository->expenseByCategoryBetween($userId, $startsAt, $endsAt);
        $spent = 0.0;

        foreach ($totals as $row) {
            if ($row->category_id !== null && isset($scopeIds[(int) $row->category_id])) {
                $spent += (float) $row->total;
            }
        }

        return round($spent, 2);
    }

    /**
     * @param  iterable<int|string, \Illuminate\Database\Eloquent\Model>  $categories
     * @return array<int, array<int, int>>
     */
    private function childrenByParent(iterable $categories): array
    {
        $children = [];

        foreach ($categories as $category) {
            if (! $category instanceof Category) {
                continue;
            }

            if ($category->parent_id !== null) {
                $children[(int) $category->parent_id][] = (int) $category->id;
            }
        }

        return $children;
    }

    /**
     * @param  array<int, int>  $selectedIds
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Model>  $byId
     * @return \Illuminate\Support\Collection<int, Category>
     */
    private function topLevelSelected(array $selectedIds, \Illuminate\Support\Collection $byId): \Illuminate\Support\Collection
    {
        $selected = array_fill_keys($selectedIds, true);
        $top = [];

        foreach ($selectedIds as $id) {
            $current = $byId->get($id);
            $hasSelectedAncestor = false;
            $seen = [];

            while ($current instanceof Category && $current->parent_id !== null) {
                $parentId = (int) $current->parent_id;

                if (isset($seen[$parentId])) {
                    break;
                }
                $seen[$parentId] = true;

                if (isset($selected[$parentId])) {
                    $hasSelectedAncestor = true;
                    break;
                }

                $current = $byId->get($parentId);
            }

            if (! $hasSelectedAncestor) {
                $category = $byId->get($id);
                if ($category instanceof Category) {
                    $top[] = $category;
                }
            }
        }

        usort($top, static fn (Category $a, Category $b): int => $a->name <=> $b->name);

        return collect($top);
    }

    /**
     * @param  array<int, array<int, int>>  $childrenByParent
     * @return array<int, int>
     */
    private function descendantIds(int $categoryId, array $childrenByParent): array
    {
        $ids = [$categoryId];
        $stack = [$categoryId];
        $seen = [$categoryId => true];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId] = true;
                $ids[] = $childId;
                $stack[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Category>  $topLevel
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Model>  $byId
     * @param  array<int, array<int, int>>  $childrenByParent
     */
    private function suggestedAmount(\Illuminate\Support\Collection $topLevel, \Illuminate\Support\Collection $byId, array $childrenByParent): float
    {
        $total = 0.0;

        foreach ($topLevel as $category) {
            $ownBudget = max(0.0, (float) $category->monthly_budget);

            if ($ownBudget > 0.0) {
                $total += $ownBudget;

                continue;
            }

            foreach ($this->descendantIds((int) $category->id, $childrenByParent) as $descendantId) {
                if ($descendantId === (int) $category->id) {
                    continue;
                }

                $descendant = $byId->get($descendantId);
                if ($descendant instanceof Category) {
                    $total += max(0.0, (float) $descendant->monthly_budget);
                }
            }
        }

        return round($total, 2);
    }

    private function shoppingDaysRemaining(CarbonImmutable $asOf, int $shoppingDay): int
    {
        if ($shoppingDay < 1 || $shoppingDay > 7) {
            return 0;
        }

        $remaining = 0;
        $cursor = $asOf->startOfDay();
        $end = $asOf->endOfMonth();

        while ($cursor->lte($end)) {
            if ($cursor->isoWeekday() === $shoppingDay) {
                $remaining++;
            }

            $cursor = $cursor->addDay();
        }

        return $remaining;
    }
}
