<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\MenuPreferenceProfile;
use App\Enums\MealEntryStatus;
use App\Models\MealPlanEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Convierte el historial aplicado en un perfil de preferencias suaves.
 *
 * Cocinar cuenta; saltar resta; una sobra cuenta algo; una comida solo
 * planificada cuenta poco. La antigüedad decae en una ventana de ocho semanas.
 * El resultado se normaliza y nunca se usa como filtro duro (ADR-0044).
 */
final class MenuPreferenceProfileBuilder
{
    private const WINDOW_DAYS = 56;

    /**
     * @param  Collection<int, MealPlanEntry>  $entries
     */
    public function build(Collection $entries, CarbonImmutable $asOf): MenuPreferenceProfile
    {
        $today = CarbonImmutable::parse($asOf->setTimezone('America/Lima')->toDateString(), 'America/Lima')->startOfDay();
        $recipeScores = [];
        $mealRecipeScores = [];
        $cuisineScores = [];
        $tagScores = [];
        $ingredientScores = [];
        $recipeNames = [];
        $ingredientNames = [];
        $prepWeighted = 0.0;
        $positiveWeight = 0.0;
        $cookedWeight = 0.0;
        $totalAbs = 0.0;

        foreach ($entries as $entry) {
            $recipe = $entry->recipe;

            if ($recipe === null) {
                continue;
            }

            $weight = $this->statusWeight($entry->status);

            if ($weight === 0.0) {
                continue;
            }

            $entryDate = CarbonImmutable::parse($entry->plan_date->toDateString(), 'America/Lima')->startOfDay();
            $daysAgo = abs((int) $today->diffInDays($entryDate));
            $recency = max(0.25, 1.0 - ($daysAgo / self::WINDOW_DAYS));
            $value = $weight * $recency;
            $recipeId = (int) $recipe->id;
            $meal = $entry->meal_type->value;

            $recipeNames[$recipeId] = (string) $recipe->name;
            $recipeScores[$recipeId] = ($recipeScores[$recipeId] ?? 0.0) + $value;
            $mealRecipeScores[$meal][$recipeId] = ($mealRecipeScores[$meal][$recipeId] ?? 0.0) + $value;
            $cuisine = $recipe->cuisine->value;
            $cuisineScores[$cuisine] = ($cuisineScores[$cuisine] ?? 0.0) + $value;
            $totalAbs += abs($value);

            if ($weight > 0.0) {
                $prepWeighted += ((int) $recipe->prep_minutes) * $value;
                $positiveWeight += $value;

                if ($entry->status === MealEntryStatus::Cooked) {
                    $cookedWeight += $value;
                }
            }

            $tags = $recipe->diet_tags ?? [];
            $tagShare = $value / max(1, count($tags));

            foreach ($tags as $tag) {
                $tagScores[$tag] = ($tagScores[$tag] ?? 0.0) + $tagShare;
            }

            $ingredients = $recipe->ingredients;
            $ingredientShare = $value / max(1, $ingredients->count());

            foreach ($ingredients as $ingredient) {
                $productId = (int) $ingredient->product_id;
                $ingredientNames[$productId] = (string) $ingredient->product->name;
                $ingredientScores[$productId] = ($ingredientScores[$productId] ?? 0.0) + $ingredientShare;
            }
        }

        $normalizedMeal = [];
        foreach ($mealRecipeScores as $meal => $scores) {
            $normalizedMeal[$meal] = $this->normalize($scores);
        }

        return new MenuPreferenceProfile(
            recipeScores: $this->normalize($recipeScores),
            mealRecipeScores: $normalizedMeal,
            cuisineScores: $this->normalize($cuisineScores),
            tagScores: $this->normalize($tagScores),
            ingredientScores: $this->normalize($ingredientScores),
            topRecipes: $this->top($recipeScores, $recipeNames, 5, true),
            avoidedRecipes: $this->top($recipeScores, $recipeNames, 3, false),
            topIngredients: $this->top($ingredientScores, $ingredientNames, 5, true),
            topCuisines: $this->top($cuisineScores, [], 3, true),
            topTags: $this->top($tagScores, [], 4, true),
            averagePrepMinutes: $positiveWeight > 0.0 ? (int) round($prepWeighted / $positiveWeight) : null,
            cookedRatio: $positiveWeight > 0.0 ? $cookedWeight / $positiveWeight : 0.0,
            hasSignal: $totalAbs >= 2.0,
        );
    }

    private function statusWeight(MealEntryStatus $status): float
    {
        return match ($status) {
            MealEntryStatus::Cooked => 1.0,
            MealEntryStatus::Leftover => 0.75,
            MealEntryStatus::Planned => 0.35,
            MealEntryStatus::Skipped => -1.25,
            MealEntryStatus::AteOut => 0.0,
        };
    }

    /**
     * @param  array<int|string, float>  $values
     * @return array<int|string, float>
     */
    private function normalize(array $values): array
    {
        $max = 0.0;

        foreach ($values as $value) {
            $max = max($max, abs($value));
        }

        if ($max <= 0.0) {
            return [];
        }

        return array_map(static fn (float $value): float => round($value / $max, 3), $values);
    }

    /**
     * @param  array<int|string, float>  $scores
     * @param  array<int|string, string>  $names
     * @return array<int, array{name: string, score: float}>
     */
    private function top(array $scores, array $names, int $limit, bool $positive): array
    {
        $filtered = array_filter(
            $scores,
            static fn (float $score): bool => $positive ? $score > 0.0 : $score < 0.0,
        );

        if ($positive) {
            arsort($filtered);
        } else {
            asort($filtered);
        }

        $rows = [];
        foreach (array_slice($filtered, 0, $limit, true) as $key => $score) {
            $rows[] = [
                'name' => $names[$key] ?? (string) $key,
                'score' => round($score, 2),
            ];
        }

        return $rows;
    }
}
