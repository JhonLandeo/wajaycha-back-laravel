<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use Carbon\CarbonImmutable;

/**
 * Compara lo que el menú estima gastar con el gasto real de comida de esa
 * semana. El gasto real es el de las categorías del presupuesto, no el de las
 * líneas del menú: una compra mixta o un snack también entran. La UI debe
 * decirlo así.
 */
final class BuildMenuSpendingAction
{
    public function __construct(
        private readonly BuildMenuShoppingListAction $shoppingList,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $userId, string $weekStart): array
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(CarbonImmutable::parse($weekStart, 'America/Lima'), $asOf);
        $plan = $this->shoppingList->execute($userId, $week->startsOn->toDateString());
        $estimate = $plan['estimate'] ?? null;
        $plannedCost = is_array($estimate) ? ($estimate['total'] ?? null) : null;
        $actualSpent = $this->foodBudgetRepository->spentBetween(
            $userId,
            $week->startsOn,
            $week->startsOn->addDays(7),
        );
        $budget = $this->foodBudgetRepository->snapshotFor($userId, $asOf);

        return [
            'week_start' => $week->startsOn->toDateString(),
            'week_end' => $week->endsOn->toDateString(),
            'planned_cost' => $plannedCost,
            'actual_spent' => $actualSpent,
            'difference' => $plannedCost !== null && $actualSpent !== null
                ? round($actualSpent - $plannedCost, 2)
                : null,
            'priced_lines' => is_array($estimate) ? ($estimate['priced_lines'] ?? 0) : 0,
            'unpriced_lines' => is_array($estimate) ? ($estimate['unpriced_lines'] ?? 0) : 0,
            'is_partial' => is_array($estimate) ? (bool) ($estimate['is_partial'] ?? false) : true,
            'weekly_target' => $budget?->weeklyTarget,
            'monthly_amount' => $budget?->monthlyAmount,
            'monthly_spent' => $budget?->spent,
            'available' => $budget?->available,
        ];
    }
}
