<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Enums\MealType;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\MealPlanRepositoryContract;

/**
 * Comidas cocinadas antes de la fecha elegida que todavía tienen porciones
 * libres. La disponibilidad se calcula acá, no en el cliente, porque las
 * sobras reclamadas pueden estar en semanas distintas a la que se está viendo.
 */
final class ListLeftoverSourcesAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
    ) {}

    /**
     * @return array<int, array{id: int, plan_date: string, meal_type: string, meal_type_label: string, recipe_name: string|null, available_servings: int}>
     */
    public function execute(int $userId, string $planDate, MealType $mealType, ?int $exceptEntryId = null): array
    {
        return $this->mealPlanRepository->leftoverSourcesFor($userId, $planDate, $mealType, $exceptEntryId)
            ->map(function (MealPlanEntry $entry): array {
                $claimed = (int) ($entry->getAttribute('claimed_servings') ?? 0);

                return [
                    'id' => (int) $entry->id,
                    'plan_date' => $entry->plan_date->toDateString(),
                    'meal_type' => $entry->meal_type->value,
                    'meal_type_label' => $entry->meal_type->label(),
                    'recipe_name' => $entry->recipe?->name,
                    'available_servings' => max(0, (int) $entry->cook_servings - (int) $entry->servings - $claimed),
                ];
            })
            ->filter(static fn (array $source): bool => $source['available_servings'] > 0)
            ->values()
            ->all();
    }
}
