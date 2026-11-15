<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Enums\DietProfile;
use App\Models\MealPlanSettings;
use App\Models\Product;
use App\Models\Recipe;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\RecipeRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\AI\OpenCodeGateway;
use App\Services\Shopping\AiRecipeDraftValidator;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pide a la IA recetas nuevas compuestas con productos del catálogo (ADR-0023).
 *
 * La IA no inventa productos: solo elige ids, cantidades y unidades que el
 * backend valida. Cada propuesta se guarda como receta privada con
 * `origin = ai`, así entra al menú, a la compra y a la despensa como cualquier
 * receta editable. El límite es cinco por semana.
 */
final class GeneratePantryRecipesAction
{
    private const MAX_PER_WEEK = 5;

    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly RecipeRepositoryContract $recipeRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly AiRecipeDraftValidator $draftValidator,
        private readonly OpenCodeGateway $gateway,
    ) {}

    public function execute(int $userId, string $weekStart): int
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            $asOf,
        );

        $settings = $this->mealPlanRepository->settingsFor($userId);
        $limit = min(self::MAX_PER_WEEK, max(0, (int) $settings->new_recipes_per_week));

        if ($limit <= 0) {
            return 0;
        }

        $remaining = $limit - $this->recipeRepository->countAiGeneratedSince($userId, $week->startsOn);

        if ($remaining <= 0) {
            return 0;
        }

        $products = $this->shoppingRepository->listProductsFor($userId);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $existing = $this->recipeRepository->allForUser($userId);

        try {
            $response = $this->gateway->generateJson([
                ['parts' => [['text' => $this->prompt($week, $settings, $products, $pantry, $existing, $remaining)]]],
            ], ['temperature' => 0.6]);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        if ($response === null || ! isset($response['recipes']) || ! is_array($response['recipes'])) {
            return 0;
        }

        $productsById = [];
        foreach ($products as $product) {
            $productsById[(int) $product->id] = $product;
        }

        $existingNames = [];
        foreach ($existing as $recipe) {
            $existingNames[$this->fold($recipe->name)] = true;
        }

        $created = 0;

        foreach ($response['recipes'] as $raw) {
            if ($created >= $remaining) {
                break;
            }

            if (! is_array($raw)) {
                continue;
            }

            $recipe = $this->draftValidator->validate($raw, $settings, $productsById, $existingNames);

            if ($recipe === null) {
                continue;
            }

            try {
                $this->recipeRepository->create($userId, $recipe['data'], $recipe['ingredients']);
                $existingNames[$this->fold($recipe['data']['name'])] = true;
                $created++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $created;
    }

    /**
     * @param  array<int, Product>  $products
     * @param  array<int, \App\DTOs\Shopping\PantryStock>  $pantry
     * @param  \Illuminate\Database\Eloquent\Collection<int, Recipe>  $existing
     */
    private function prompt(
        PlanningWeek $week,
        MealPlanSettings $settings,
        array $products,
        array $pantry,
        $existing,
        int $limit,
    ): string {
        $today = $week->asOf->setTimezone('America/Lima')->toDateString();

        $productList = array_map(static fn (Product $product): array => [
            'id' => (int) $product->id,
            'nombre' => $product->name,
            'unidad' => $product->unit,
        ], $products);

        $pantryList = [];
        foreach ($pantry as $stock) {
            if ($stock->unit === null || $stock->quantity <= 0.0) {
                continue;
            }

            if ($stock->expiresOn !== null && $stock->expiresOn->toDateString() < $today) {
                continue;
            }

            $pantryList[] = [
                'producto_id' => $stock->productId,
                'producto' => $stock->productName,
                'cantidad' => $stock->quantity,
                'unidad' => $stock->unit->value,
                'vence' => $stock->expiresOn?->toDateString(),
            ];
        }

        $existingList = $existing->map(static fn (Recipe $recipe): array => [
            'nombre' => $recipe->name,
            'comidas' => $recipe->meal_types,
            'ingredientes' => $recipe->ingredients
                ->map(static fn ($ingredient): string => (string) $ingredient->product->name)
                ->filter()
                ->values()
                ->all(),
        ])->values()->all();

        $context = [
            'semana' => ['inicio' => $week->startsOn->toDateString(), 'fin' => $week->endsOn->toDateString()],
            'personas' => (int) $settings->household_size,
            'comidas_activas' => $settings->meal_types,
            'tiempo_maximo' => $settings->max_prep_minutes,
            'perfil' => ($settings->diet_profile ?? DietProfile::Balanced)->value,
            'equipamiento_disponible' => $settings->available_equipment,
            'no_incluir' => $settings->excluded_terms,
            'despensa' => $pantryList,
            'productos' => $productList,
            'recetas_existentes' => $existingList,
        ];

        return <<<PROMPT
Eres un cocinero peruano que arma recetas con lo que hay en casa. Propón hasta {$limit} recetas nuevas y devuelve solo JSON válido.

Reglas:
- Usa únicamente `producto_id` del catálogo entregado. No inventes productos ni ids.
- Prioriza los ingredientes de la despensa, sobre todo los que vencen pronto, y los productos que las recetas existentes no usan.
- No repitas una receta existente ni una combinación ya propuesta.
- Cada receta debe llevar: `name`, `meal_types` (subconjunto de `comidas_activas`), `course` (`main` o `drink`), `prep_minutes`, `equipment` (subconjunto de `equipamiento_disponible`; si es null, no pidas equipamiento especial), `instructions` con los pasos de preparación en texto claro e `ingredients` con `producto_id`, `quantity` y `unit` compatible con la unidad del producto.
- `base_servings` no se envía: el backend usa el número de personas.
- No uses productos de `no_incluir` en el nombre ni en los ingredientes. Respeta el tiempo máximo.
- No expliques nada fuera del JSON.

Contexto:
```json
{$this->json($context)}
```

Formato exacto:
{"recipes":[{"name":"Pescado con verduras al vapor","meal_types":["lunch"],"course":"main","prep_minutes":25,"equipment":["stove"],"instructions":"1. Sazona el pescado...","ingredients":[{"producto_id":1,"quantity":0.4,"unit":"kg"}]}]}
PROMPT;
    }

    private function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
