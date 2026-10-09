<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Shopping\GroceryCategoryMatch;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\Enums\BudgetPeriod;
use App\Models\Category;
use App\Models\GroceryBudgetLink;
use App\Repositories\Contracts\CategoryRepositoryContract;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

final class GroceryBudgetRepository implements GroceryBudgetRepositoryContract
{
    public function __construct(
        private readonly CategoryRepositoryContract $categoryRepository,
        private readonly TransactionRepositoryContract $transactionRepository,
    ) {}

    public function findLink(int $userId): ?GroceryBudgetLink
    {
        /** @var GroceryBudgetLink|null $link */
        $link = GroceryBudgetLink::query()->where('user_id', $userId)->first();

        return $link;
    }

    public function findCategoryByExactName(int $userId, string $name): ?GroceryCategoryMatch
    {
        $category = $this->categoryRepository->getAllForUser($userId)
            ->first(fn (Model $candidate): bool => $candidate instanceof Category && $candidate->name === $name);

        if (! $category instanceof Category) {
            return null;
        }

        return new GroceryCategoryMatch($category->id, $category->name);
    }

    public function pin(int $userId, int $categoryId, string $resolvedBy): GroceryBudgetLink
    {
        $now = CarbonImmutable::now();

        // A genuine upsert on user_id — Model::upsert() compiles to
        // PostgreSQL's own `INSERT ... ON CONFLICT (user_id) DO UPDATE`
        // (the unq_grocery_budget_links_user_id target), so the constraint
        // is the sole concurrency arbiter for the auto-resolve race
        // (design.md D6) and this same statement also serves the manual
        // override's "replace whichever category was pinned before".
        // upsert() bypasses Eloquent events/casts and returns an affected-
        // row count, not a model, so the row is re-read below.
        GroceryBudgetLink::upsert(
            [[
                'user_id' => $userId,
                'category_id' => $categoryId,
                'resolved_by' => $resolvedBy,
                'linked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            uniqueBy: ['user_id'],
            update: ['category_id', 'resolved_by', 'linked_at', 'updated_at'],
        );

        $link = $this->findLink($userId);

        if ($link === null) {
            // Unreachable in practice — the upsert above just wrote this
            // exact row — but findLink() is honestly nullable, so this
            // keeps the return type true rather than asserting past it.
            throw new \RuntimeException('El pin de grocery_budget_links no se pudo releer tras escribirlo.');
        }

        return $link;
    }

    public function ceilingFor(int $userId, CarbonImmutable $asOf): ?GroceryCeilingInput
    {
        $link = $this->findLink($userId);

        if ($link === null) {
            return null;
        }

        $category = $this->categoryRepository->findById((int) $link->category_id, $userId);

        if ($category === null) {
            // Defensive: the composite FK's ON DELETE CASCADE should have
            // removed the link along with the Category. A miss here still
            // collapses to "unlinked" rather than surfacing a stale id.
            return null;
        }

        $budgetPeriod = BudgetPeriod::fromColumn($category->budget_period);
        [$startsAt, $endsAt] = $this->windowFor($asOf, $budgetPeriod);

        $totals = $this->transactionRepository->expenseByCategoryBetween($userId, $startsAt, $endsAt);
        $spent = 0.0;
        foreach ($totals as $row) {
            if ($row->category_id !== null && (int) $row->category_id === $category->id) {
                $spent = (float) $row->total;
                break;
            }
        }

        return new GroceryCeilingInput(
            categoryId: $category->id,
            categoryName: $category->name,
            monthlyBudget: (float) $category->monthly_budget,
            budgetPeriod: $budgetPeriod,
            spent: $spent,
        );
    }

    /**
     * design.md D5's window table: MONTHLY reads the calendar month `$asOf`
     * falls in; YEARLY reads the calendar year, never a twelfth of it. Both
     * windows are half-open, matching
     * `TransactionRepositoryContract::expenseByCategoryBetween()`'s own
     * contract.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowFor(CarbonImmutable $asOf, BudgetPeriod $budgetPeriod): array
    {
        return match ($budgetPeriod) {
            BudgetPeriod::YEARLY => [$asOf->startOfYear(), $asOf->startOfYear()->addYear()],
            BudgetPeriod::MONTHLY => [$asOf->startOfMonth(), $asOf->startOfMonth()->addMonthNoOverflow()],
        };
    }
}
