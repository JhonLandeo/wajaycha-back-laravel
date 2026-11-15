<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\Models\MealPlanSettings;
use App\Models\Recipe;
use App\Repositories\Contracts\RecipeRepositoryContract;
use Illuminate\Support\Str;

/**
 * Filtro duro del catálogo antes de cualquier decisión (ADR-0015, decisión 1).
 *
 * La IA y el chat solo ven recetas que ya pasaron por aquí: comidas activas,
 * tiempo máximo y términos excluidos. Un plato filtrado no puede reaparecer por
 * una alucinación del modelo.
 */
final class MenuCandidateFilter
{
    public function __construct(
        private readonly RecipeRepositoryContract $recipeRepository,
    ) {}

    /**
     * @return array<string, array<int, Recipe>>
     */
    public function forUser(int $userId, MealPlanSettings $settings): array
    {
        $recipes = $this->recipeRepository->allForUser($userId);
        $excluded = array_map(fn (string $term): string => $this->fold($term), $settings->excluded_terms ?? []);
        $byMeal = [];

        foreach ($recipes as $recipe) {
            if ($settings->max_prep_minutes !== null
                && $recipe->prep_minutes !== null
                && $recipe->prep_minutes > $settings->max_prep_minutes) {
                continue;
            }

            $haystack = $this->fold($recipe->name.' '.$recipe->ingredients
                ->map(fn ($ingredient): string => (string) $ingredient->product->name)
                ->implode(' '));

            if ($excluded !== [] && array_filter($excluded, fn (string $term): bool => $term !== '' && str_contains($haystack, $term)) !== []) {
                continue;
            }

            if (! $this->hasEquipment($recipe, $settings)) {
                continue;
            }

            foreach ($recipe->meal_types as $meal) {
                if (in_array($meal, $settings->meal_types, true)) {
                    $byMeal[$meal][] = $recipe;
                }
            }
        }

        return $byMeal;
    }

    private function hasEquipment(Recipe $recipe, MealPlanSettings $settings): bool
    {
        $available = $settings->available_equipment;

        if ($available === null) {
            return true;
        }

        foreach ($recipe->required_equipment ?? [] as $equipment) {
            if (! in_array($equipment, $available, true)) {
                return false;
            }
        }

        return true;
    }

    public function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
