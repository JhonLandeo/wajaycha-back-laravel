<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\DietProfile;
use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\MealType;
use App\Enums\MenuStrategy;
use App\Enums\RecipeCourse;
use App\Enums\RecipeCuisine;
use App\Models\MealPlanSettings;
use App\Models\Recipe;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Shopping\MealPlanPresenter;
use App\Services\Shopping\MenuCalendar;
use App\Services\Shopping\MenuCandidateFilter;
use App\Services\Shopping\NutritionCalculator;
use App\Services\Shopping\PantryPriority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Propone otra receta para una comida concreta del preview.
 *
 * No escribe nada: el preview sigue viviendo en el cliente hasta que se aplica
 * (ADR-0027, ADR-0032). La alternativa evita la receta actual y, cuando puede,
 * las que ya aparecen en la semana; además respeta el perfil, el límite de
 * carbohidratos de la cena y el objetivo calórico declarado (ADR-0031).
 */
final class SuggestMealAlternativeAction
{
    private const MAX_RECIPE_OCCURRENCES = 1;

    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly MenuCandidateFilter $candidateFilter,
        private readonly NutritionCalculator $nutritionCalculator,
        private readonly PantryPriority $pantryPriority,
        private readonly MenuCalendar $calendar,
        private readonly MealPlanPresenter $presenter,
    ) {}

    /**
     * @param  array<int, mixed>  $entries
     * @return array<string, mixed>|null
     */
    public function execute(int $userId, string $weekStart, string $date, string $meal, array $entries): ?array
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            $asOf,
        );
        $settings = $this->mealPlanRepository->settingsFor($userId);
        $days = $this->calendar->upcomingActiveDays($week, $settings->plan_weekdays);

        if (! in_array($date, $days, true) || ! in_array($meal, $settings->meal_types, true)) {
            return null;
        }

        $candidates = $this->candidateFilter->forUser($userId, $settings)[$meal] ?? [];
        $mains = array_values(array_filter(
            $candidates,
            static fn (Recipe $recipe): bool => $recipe->course !== RecipeCourse::Drink,
        ));

        if ($mains === []) {
            return null;
        }

        [$usage, $currentRecipeId, $currentEntryId] = $this->usageFrom($entries, $date, $meal);
        $pool = array_values(array_filter(
            $mains,
            static fn (Recipe $recipe): bool => (int) $recipe->id !== $currentRecipeId,
        ));

        if ($pool === []) {
            return null;
        }

        $pool = $this->applyProfilePreference($pool, $settings);
        $pool = $this->applyDinnerCarbPreference($pool, $meal, $settings);

        $pool = array_values(array_filter(
            $pool,
            static fn (Recipe $recipe): bool => ($usage[(int) $recipe->id] ?? 0) < self::MAX_RECIPE_OCCURRENCES,
        ));

        if ($pool === []) {
            return null;
        }

        $pool = $this->applyPantryPriority($pool, $userId, $asOf, $settings);

        $habitProductIds = array_map(
            static fn ($habit): int => (int) $habit->product_id,
            $this->shoppingRepository->activeHabitsFor($userId),
        );

        $sharedProductIds = $this->sharedProductIdsFromEntries($entries, $date, $meal);

        usort($pool, function (Recipe $a, Recipe $b) use ($usage, $settings, $meal, $habitProductIds, $sharedProductIds): int {
            $scoreA = $this->score($a, $usage, $settings, $meal, $habitProductIds, $sharedProductIds);
            $scoreB = $this->score($b, $usage, $settings, $meal, $habitProductIds, $sharedProductIds);

            return $scoreB <=> $scoreA ?: $a->name <=> $b->name;
        });

        $recipe = $pool[0];
        $drink = $this->chooseDrink($candidates, (int) $recipe->id, $usage, $settings, $meal);
        $entryId = $currentEntryId !== null
            ? $currentEntryId
            : -1 * count($entries) - 1;

        return $this->presenter->entry($this->transientEntry(
            $entryId,
            $date,
            $meal,
            $recipe,
            $drink,
            (int) $settings->household_size,
        ));
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array{0: array<int, int>, 1: int|null, 2: int|null}
     */
    private function usageFrom(array $entries, string $date, string $meal): array
    {
        $usage = [];
        $currentRecipeId = null;
        $currentEntryId = null;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $recipeId = (int) ($entry['recipe_id'] ?? 0);

            if ($recipeId > 0) {
                $usage[$recipeId] = ($usage[$recipeId] ?? 0) + 1;
            }

            if ((string) ($entry['plan_date'] ?? '') === $date && (string) ($entry['meal_type'] ?? '') === $meal) {
                $currentRecipeId = $recipeId > 0 ? $recipeId : null;
                $currentEntryId = (int) ($entry['id'] ?? 0) ?: null;
            }
        }

        return [$usage, $currentRecipeId, $currentEntryId];
    }

    /**
     * @param  array<int, Recipe>  $pool
     * @return array<int, Recipe>
     */
    private function applyProfilePreference(array $pool, MealPlanSettings $settings): array
    {
        // Ligero no reemplaza el pool: es un bonus en `score()`, para que una
        // sola receta etiquetada no convierta la comida en la misma todos los
        // días (ADR-0033). Alto en proteína conserva su preferencia fuerte.
        if (($settings->diet_profile ?? DietProfile::Balanced) !== DietProfile::HighProtein) {
            return $pool;
        }

        $tagged = array_values(array_filter(
            $pool,
            static fn (Recipe $recipe): bool => in_array('high_protein', $recipe->diet_tags ?? [], true),
        ));

        return $tagged !== [] ? $tagged : $pool;
    }

    /**
     * @param  array<int, Recipe>  $pool
     * @return array<int, Recipe>
     */
    private function applyPantryPriority(
        array $pool,
        int $userId,
        CarbonImmutable $asOf,
        MealPlanSettings $settings,
    ): array {
        if (($settings->menu_strategy ?? MenuStrategy::Balanced) !== MenuStrategy::UsePantry) {
            return $pool;
        }

        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $priorityOrder = $this->pantryPriority->orderedProductIds($pantry, $asOf);

        foreach ($priorityOrder as $productId) {
            if (! $this->hasStock($pantry, $productId)) {
                continue;
            }

            $withPriority = array_values(array_filter(
                $pool,
                fn (Recipe $recipe): bool => $this->recipeUsesProduct($recipe, $productId),
            ));

            if ($withPriority !== []) {
                return $withPriority;
            }
        }

        return $pool;
    }

    /**
     * @param  array<int, PantryStock>  $pantry
     */
    private function hasStock(array $pantry, int $productId): bool
    {
        foreach ($pantry as $stock) {
            if ($stock->productId === $productId && $stock->unit !== null && $stock->quantity > 0.0) {
                return true;
            }
        }

        return false;
    }

    private function recipeUsesProduct(Recipe $recipe, int $productId): bool
    {
        foreach ($recipe->ingredients as $ingredient) {
            if ((int) $ingredient->product_id === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<int, true>
     */
    private function sharedProductIdsFromEntries(array $entries, string $date, string $meal): array
    {
        $productIds = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)
                || (string) ($entry['plan_date'] ?? '') !== $date
                || (string) ($entry['meal_type'] ?? '') === $meal) {
                continue;
            }

            foreach ([$entry['recipe'] ?? null, $entry['drink_recipe'] ?? null] as $recipe) {
                if (! is_array($recipe) || ! isset($recipe['ingredients']) || ! is_array($recipe['ingredients'])) {
                    continue;
                }

                foreach ($recipe['ingredients'] as $ingredient) {
                    if (is_array($ingredient) && isset($ingredient['product_id'])) {
                        $productIds[(int) $ingredient['product_id']] = true;
                    }
                }
            }
        }

        return $productIds;
    }

    /**
     * @param  array<int, Recipe>  $pool
     * @return array<int, Recipe>
     */
    private function applyDinnerCarbPreference(array $pool, string $meal, MealPlanSettings $settings): array
    {
        if ($meal !== 'dinner') {
            return $pool;
        }

        $limit = $settings->dinner_carb_limit_g;

        if ($limit !== null) {
            $lowCarb = array_values(array_filter(
                $pool,
                fn (Recipe $recipe): bool => $this->nutritionCalculator->perServing($recipe)->isComplete()
                    && $this->nutritionCalculator->perServing($recipe)->carbsG <= $limit,
            ));

            if ($lowCarb !== []) {
                $pool = $lowCarb;
            }
        }

        $calorieLimit = $settings->dinner_calorie_limit;

        if ($calorieLimit !== null) {
            $lowerCalories = array_values(array_filter(
                $pool,
                fn (Recipe $recipe): bool => $this->nutritionCalculator->perServing($recipe)->isComplete()
                    && $this->nutritionCalculator->perServing($recipe)->kcal <= $calorieLimit,
            ));

            if ($lowerCalories !== []) {
                $pool = $lowerCalories;
            }
        }

        return $pool;
    }

    /**
     * @param  array<int, int>  $usage
     * @param  array<int, int>  $habitProductIds
     * @param  array<int, true>  $sharedProductIds
     */
    private function score(
        Recipe $recipe,
        array $usage,
        MealPlanSettings $settings,
        string $meal,
        array $habitProductIds,
        array $sharedProductIds,
    ): float {
        $nutrition = $this->nutritionCalculator->perServing($recipe);
        $score = -6.0 * ($usage[(int) $recipe->id] ?? 0);

        if (($recipe->cuisine ?? RecipeCuisine::Peruvian) === RecipeCuisine::Peruvian) {
            $score += 2.0;
        }

        foreach ($recipe->ingredients as $ingredient) {
            if (in_array((int) $ingredient->product_id, $habitProductIds, true)) {
                $score += 2.0;
                break;
            }
        }

        $sharedBonus = 0.0;

        foreach ($recipe->ingredients as $ingredient) {
            if (isset($sharedProductIds[(int) $ingredient->product_id])) {
                $sharedBonus += 1.5;
            }
        }

        $score += min(3.0, $sharedBonus);

        if (! $nutrition->isComplete()) {
            return $score;
        }

        $target = $settings->daily_calorie_target;

        if ($target !== null) {
            $share = $target / max(1, count($settings->meal_types));
            $delta = $nutrition->kcal - $share;
            $score += $delta > 0 ? -min(6.0, $delta / 80.0) : 1.5;
        }

        $limit = $settings->dinner_carb_limit_g;

        if ($meal === 'dinner' && $limit !== null) {
            $score += $nutrition->carbsG <= $limit
                ? 5.0
                : -min(10.0, ($nutrition->carbsG - $limit) / 5.0);
        }

        $profile = $settings->diet_profile ?? DietProfile::Balanced;

        if ($profile === DietProfile::HighProtein) {
            $score += min(3.0, $nutrition->proteinG / 15.0);
        }

        if ($profile === DietProfile::Light && in_array('light', $recipe->diet_tags ?? [], true)) {
            $score += 5.0;
        }

        if ($meal === 'dinner') {
            if (in_array('light', $recipe->diet_tags ?? [], true)) {
                $score += 5.0;
            }

            $calorieLimit = $settings->dinner_calorie_limit;

            if ($calorieLimit !== null) {
                $score += $nutrition->kcal <= $calorieLimit
                    ? 3.0
                    : -min(10.0, ($nutrition->kcal - $calorieLimit) / 50.0);
            }

            $targetMinutes = $settings->dinner_max_prep_minutes;

            if ($targetMinutes !== null && $recipe->prep_minutes !== null) {
                $delay = $recipe->prep_minutes - $targetMinutes;

                if ($delay > 0) {
                    $score -= min(4.0, $delay * 0.8);
                }
            }
        }

        return $score;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, int>  $usage
     */
    private function chooseDrink(
        array $candidates,
        int $mainId,
        array $usage,
        MealPlanSettings $settings,
        string $meal,
    ): ?Recipe {
        $drinks = array_values(array_filter(
            $candidates,
            static fn (Recipe $recipe): bool => $recipe->course === RecipeCourse::Drink
                && (int) $recipe->id !== $mainId,
        ));

        if ($drinks === []) {
            return null;
        }

        $limit = $settings->dinner_carb_limit_g;

        if ($meal === 'dinner' && $limit !== null) {
            $lowCarb = array_values(array_filter(
                $drinks,
                fn (Recipe $recipe): bool => $this->nutritionCalculator->perServing($recipe)->isComplete()
                    && $this->nutritionCalculator->perServing($recipe)->carbsG <= $limit,
            ));

            if ($lowCarb !== []) {
                $drinks = $lowCarb;
            }
        }

        $usable = array_values(array_filter(
            $drinks,
            static fn (Recipe $recipe): bool => ($usage[(int) $recipe->id] ?? 0) < self::MAX_RECIPE_OCCURRENCES,
        ));

        if ($usable !== []) {
            $drinks = $usable;
        }

        usort($drinks, static fn (Recipe $a, Recipe $b): int => (($usage[(int) $a->id] ?? 0) <=> ($usage[(int) $b->id] ?? 0))
            ?: $a->name <=> $b->name);

        return $drinks[0];
    }

    private function transientEntry(
        int $id,
        string $date,
        string $meal,
        Recipe $recipe,
        ?Recipe $drink,
        int $servings,
    ): \App\Models\MealPlanEntry {
        $entry = new \App\Models\MealPlanEntry([
            'plan_date' => $date,
            'meal_type' => $meal,
            'kind' => MealEntryKind::Recipe->value,
            'status' => MealEntryStatus::Planned->value,
            'recipe_id' => (int) $recipe->id,
            'drink_recipe_id' => $drink === null ? null : (int) $drink->id,
            'servings' => $servings,
            'cook_servings' => $servings,
            'leftover_of_entry_id' => null,
            'notes' => null,
        ]);

        $entry->id = $id;
        $entry->plan_date = Carbon::parse($date, 'America/Lima');
        $entry->meal_type = MealType::from($meal);
        $entry->kind = MealEntryKind::Recipe;
        $entry->status = MealEntryStatus::Planned;
        $entry->setRelation('recipe', $recipe);
        $entry->setRelation('drinkRecipe', $drink);
        $entry->setRelation('leftoverOf', null);

        return $entry;
    }
}
