<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use App\Enums\BudgetPeriod;
use Carbon\CarbonImmutable;

/**
 * The `set`-state ceiling, already carrying whether it is exceeded
 * (design.md D5, "Output"). Built from `GroceryCeilingInput` plus the
 * window `GroceryBudgetRepositoryContract::ceilingFor()` read against —
 * `ShoppingPlanService` computes `$isExceeded`/`$overspend`/`$remaining`
 * itself so a wrong overspend figure is traceable to `$amount` and
 * `$spent` rather than hidden behind a pre-computed boolean.
 */
final class GroceryCeilingReading
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryName,
        public readonly float $amount,
        public readonly float $spent,
        public readonly float $remaining,
        public readonly bool $isExceeded,
        public readonly float $overspend,
        public readonly BudgetPeriod $budgetPeriod,
        public readonly CarbonImmutable $windowStartsAt,
        public readonly CarbonImmutable $windowEndsAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'amount' => $this->amount,
            'spent' => $this->spent,
            'remaining' => $this->remaining,
            'is_exceeded' => $this->isExceeded,
            'overspend' => $this->overspend,
            'budget_period' => $this->budgetPeriod->value,
            'window_starts_at' => $this->windowStartsAt->toDateString(),
            'window_ends_at' => $this->windowEndsAt->toDateString(),
        ];
    }
}
