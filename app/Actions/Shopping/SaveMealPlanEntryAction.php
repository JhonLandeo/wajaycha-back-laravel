<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Enums\MealEntryKind;
use App\Enums\MealType;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\RecipeRepositoryContract;
use Illuminate\Validation\ValidationException;

/**
 * Decide y escribe una entrada del menú. La validación de dominio vive acá, no
 * en el FormRequest: depende de la entrada existente, de la receta elegida y de
 * las sobras ya consumidas.
 */
final class SaveMealPlanEntryAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly RecipeRepositoryContract $recipeRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(int $userId, array $data): MealPlanEntry
    {
        $mealType = MealType::from((string) $data['meal_type']);
        $kind = MealEntryKind::from((string) $data['kind']);
        $planDate = (string) $data['plan_date'];
        $existing = $this->mealPlanRepository->findSlot($userId, $planDate, $mealType);

        return match ($kind) {
            MealEntryKind::Recipe => $this->recipeEntry($userId, $planDate, $mealType, $existing, $data),
            MealEntryKind::Leftover => $this->leftoverEntry($userId, $planDate, $mealType, $existing, $data),
            MealEntryKind::EatOut, MealEntryKind::Free => $this->emptyEntry($userId, $planDate, $mealType, $existing, $kind, $data),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recipeEntry(
        int $userId,
        string $planDate,
        MealType $mealType,
        ?MealPlanEntry $existing,
        array $data,
    ): MealPlanEntry {
        $recipeId = (int) ($data['recipe_id'] ?? 0);
        $recipe = $this->recipeRepository->findForUser($recipeId, $userId);

        if ($recipe === null) {
            throw ValidationException::withMessages(['recipe_id' => 'Elige una receta válida.']);
        }

        if (! in_array($mealType->value, $recipe->meal_types, true)) {
            throw ValidationException::withMessages(['recipe_id' => 'Esa receta no corresponde a esta comida.']);
        }

        $servings = (int) ($data['servings'] ?? 0);
        if ($servings < 1) {
            throw ValidationException::withMessages(['servings' => 'Indica al menos una porción.']);
        }

        $cookServings = (int) ($data['cook_servings'] ?? $servings);
        if ($cookServings < $servings) {
            throw ValidationException::withMessages(['cook_servings' => 'No puedes cocinar menos porciones de las que se comen.']);
        }

        if ($existing !== null && $existing->leftovers()->exists()) {
            if ((int) $existing->recipe_id !== $recipeId) {
                throw ValidationException::withMessages(['recipe_id' => 'Esta comida ya tiene sobras asociadas; elimínalas antes de cambiar la receta.']);
            }

            $claimed = $this->mealPlanRepository->leftoverServingsUsed($existing->id);
            if ($cookServings - $servings < $claimed) {
                throw ValidationException::withMessages(['cook_servings' => 'No puedes cocinar menos porciones: ya hay sobras asociadas a esta comida.']);
            }
        }

        return $this->mealPlanRepository->upsertEntry($userId, [
            'plan_date' => $planDate,
            'meal_type' => $mealType->value,
            'kind' => MealEntryKind::Recipe->value,
            'recipe_id' => $recipeId,
            'servings' => $servings,
            'cook_servings' => $cookServings,
            'leftover_of_entry_id' => null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function leftoverEntry(
        int $userId,
        string $planDate,
        MealType $mealType,
        ?MealPlanEntry $existing,
        array $data,
    ): MealPlanEntry {
        if ($existing !== null && $existing->leftovers()->exists()) {
            throw ValidationException::withMessages(['kind' => 'Esta comida ya tiene sobras asociadas; elimínalas antes de cambiarla.']);
        }

        $sourceId = (int) ($data['leftover_of_entry_id'] ?? 0);
        $source = $this->mealPlanRepository->findEntry($sourceId, $userId);

        if ($source === null || $source->kind !== MealEntryKind::Recipe || $source->recipe_id === null) {
            throw ValidationException::withMessages(['leftover_of_entry_id' => 'Elige una comida cocinada que tenga sobras.']);
        }

        if ($planDate <= $source->plan_date->toDateString()) {
            throw ValidationException::withMessages(['plan_date' => 'Las sobras deben ir después del día en que se cocinó la comida.']);
        }

        $servings = (int) ($data['servings'] ?? 0);
        if ($servings < 1) {
            throw ValidationException::withMessages(['servings' => 'Indica al menos una porción de sobras.']);
        }

        $claimed = $this->mealPlanRepository->leftoverServingsUsed($source->id, $existing?->id);
        $available = $source->cook_servings - $source->servings - $claimed;

        if ($servings > $available) {
            throw ValidationException::withMessages(['servings' => 'No hay suficientes porciones de sobras en esa comida.']);
        }

        return $this->mealPlanRepository->upsertEntry($userId, [
            'plan_date' => $planDate,
            'meal_type' => $mealType->value,
            'kind' => MealEntryKind::Leftover->value,
            'recipe_id' => $source->recipe_id,
            'servings' => $servings,
            'cook_servings' => 0,
            'leftover_of_entry_id' => $source->id,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emptyEntry(
        int $userId,
        string $planDate,
        MealType $mealType,
        ?MealPlanEntry $existing,
        MealEntryKind $kind,
        array $data,
    ): MealPlanEntry {
        if ($existing !== null && $existing->leftovers()->exists()) {
            throw ValidationException::withMessages(['kind' => 'Esta comida ya tiene sobras asociadas; elimínalas antes de cambiarla.']);
        }

        return $this->mealPlanRepository->upsertEntry($userId, [
            'plan_date' => $planDate,
            'meal_type' => $mealType->value,
            'kind' => $kind->value,
            'recipe_id' => null,
            'servings' => 0,
            'cook_servings' => 0,
            'leftover_of_entry_id' => null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
