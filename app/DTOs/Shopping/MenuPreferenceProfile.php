<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

/**
 * Preferencias suaves derivadas del historial aplicado del usuario (ADR-0044).
 *
 * Los valores están normalizados a [-1, 1] por dimensión. No son un filtro:
 * la sugerencia los usa como desempate y el prompt los recibe como tendencia.
 */
final class MenuPreferenceProfile
{
    /**
     * @param  array<int, float>  $recipeScores
     * @param  array<string, array<int, float>>  $mealRecipeScores
     * @param  array<string, float>  $cuisineScores
     * @param  array<string, float>  $tagScores
     * @param  array<int, float>  $ingredientScores
     * @param  array<int, array{name: string, score: float}>  $topRecipes
     * @param  array<int, array{name: string, score: float}>  $avoidedRecipes
     * @param  array<int, array{name: string, score: float}>  $topIngredients
     * @param  array<int, array{name: string, score: float}>  $topCuisines
     * @param  array<int, array{name: string, score: float}>  $topTags
     */
    public function __construct(
        public readonly array $recipeScores,
        public readonly array $mealRecipeScores,
        public readonly array $cuisineScores,
        public readonly array $tagScores,
        public readonly array $ingredientScores,
        public readonly array $topRecipes,
        public readonly array $avoidedRecipes,
        public readonly array $topIngredients,
        public readonly array $topCuisines,
        public readonly array $topTags,
        public readonly ?int $averagePrepMinutes,
        public readonly float $cookedRatio,
        public readonly bool $hasSignal,
    ) {}

    public static function empty(): self
    {
        return new self([], [], [], [], [], [], [], [], [], [], null, 0.0, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPromptArray(): array
    {
        return [
            'recetas_favoritas' => $this->topRecipes,
            'recetas_evitadas' => $this->avoidedRecipes,
            'ingredientes' => $this->topIngredients,
            'cocinas' => $this->topCuisines,
            'etiquetas' => $this->topTags,
            'tiempo_promedio_minutos' => $this->averagePrepMinutes,
            'proporcion_cocinada' => round($this->cookedRatio, 2),
        ];
    }
}
