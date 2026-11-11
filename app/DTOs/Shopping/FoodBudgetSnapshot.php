<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

/**
 * Lectura completa del presupuesto de comida: el monto explícito, el gasto del
 * alcance en el mes, el objetivo semanal y las categorías elegidas.
 *
 * `$categories` son las categorías de alcance de primer nivel; sus hijas se
 * incluyen en `$spent` pero no se listan acá. `$primaryCategoryId` y
 * `$categoryName` existen para reutilizar el DTO del techo del plan semanal sin
 * exponer modelos.
 */
final class FoodBudgetSnapshot
{
    /**
     * @param  array<int, FoodBudgetCategory>  $categories
     */
    public function __construct(
        public readonly float $monthlyAmount,
        public readonly float $spent,
        public readonly float $available,
        public readonly bool $isExceeded,
        public readonly float $overspend,
        public readonly int $shoppingDay,
        public readonly int $shoppingDaysRemaining,
        public readonly ?float $weeklyTarget,
        public readonly float $suggestedAmount,
        public readonly array $categories,
        public readonly ?int $primaryCategoryId,
        public readonly ?string $categoryName,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monthly_amount' => $this->monthlyAmount,
            'spent' => $this->spent,
            'available' => $this->available,
            'is_exceeded' => $this->isExceeded,
            'overspend' => $this->overspend,
            'shopping_day' => $this->shoppingDay,
            'shopping_days_remaining' => $this->shoppingDaysRemaining,
            'weekly_target' => $this->weeklyTarget,
            'suggested_amount' => $this->suggestedAmount,
            'primary_category_id' => $this->primaryCategoryId,
            'category_name' => $this->categoryName,
            'category_ids' => array_map(
                static fn (FoodBudgetCategory $category): int => $category->id,
                $this->categories
            ),
            'categories' => array_map(
                static fn (FoodBudgetCategory $category): array => $category->toArray(),
                $this->categories
            ),
        ];
    }
}
