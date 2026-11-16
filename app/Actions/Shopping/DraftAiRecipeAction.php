<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Enums\DietProfile;
use App\Models\MealPlanSettings;
use App\Models\Product;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\AI\OpenCodeGateway;
use App\Services\Shopping\AiRecipeDraftValidator;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pide a la IA un borrador de receta para el formulario (ADR-0025).
 *
 * Devuelve nombre, ingredientes del catálogo y pasos de preparación sin guardar
 * nada: el usuario revisa, edita y recién entonces usa el alta normal de
 * recetas. La IA no inventa productos ni escribe filas.
 */
final class DraftAiRecipeAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly AiRecipeDraftValidator $validator,
        private readonly OpenCodeGateway $gateway,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(int $userId, ?string $idea, bool $usePantry = false): ?array
    {
        $settings = $this->mealPlanRepository->settingsFor($userId);
        $products = $this->shoppingRepository->listProductsFor($userId);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);

        try {
            $response = $this->gateway->generateJson([
                ['parts' => [['text' => $this->prompt($settings, $products, $pantry, $idea, $usePantry)]]],
            ], ['temperature' => 0.7]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($response === null || ! isset($response['recipe']) || ! is_array($response['recipe'])) {
            return null;
        }

        $productsById = [];
        foreach ($products as $product) {
            $productsById[(int) $product->id] = $product;
        }

        $existingNames = [];
        $draft = $this->validator->validate($response['recipe'], $settings, $productsById, $existingNames);

        if ($draft === null) {
            return null;
        }

        $ingredients = array_map(static fn (array $ingredient): array => [
            'product_id' => $ingredient['product_id'],
            'product_name' => (string) $productsById[$ingredient['product_id']]->name,
            'quantity' => $ingredient['quantity'],
            'unit' => $ingredient['unit'],
            'is_optional' => $ingredient['is_optional'],
        ], $draft['ingredients']);

        return [
            'name' => $draft['data']['name'],
            'base_servings' => $draft['data']['base_servings'],
            'meal_types' => $draft['data']['meal_types'],
            'course' => $draft['data']['course'],
            'prep_minutes' => $draft['data']['prep_minutes'],
            'required_equipment' => $draft['data']['required_equipment'],
            'diet_tags' => $draft['data']['diet_tags'],
            'instructions' => $draft['data']['instructions'],
            'ingredients' => $ingredients,
        ];
    }

    /**
     * @param  array<int, Product>  $products
     * @param  array<int, \App\DTOs\Shopping\PantryStock>  $pantry
     */
    private function prompt(
        MealPlanSettings $settings,
        array $products,
        array $pantry,
        ?string $idea,
        bool $usePantry,
    ): string {
        $today = CarbonImmutable::now()->setTimezone('America/Lima')->toDateString();

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

        $context = [
            'idea' => $idea !== null && trim($idea) !== ''
                ? trim($idea)
                : ($usePantry ? 'Una receta para aprovechar la despensa.' : 'Una receta peruana tradicional.'),
            'personas' => (int) $settings->household_size,
            'comidas_activas' => $settings->meal_types,
            'tiempo_maximo' => $settings->max_prep_minutes,
            'equipamiento_disponible' => $settings->available_equipment,
            'perfil' => ($settings->diet_profile ?? DietProfile::Balanced)->value,
            'no_incluir' => $settings->excluded_terms,
            'productos' => $productList,
        ];

        if ($usePantry) {
            $context['despensa'] = $pantryList;
        }

        $pantryRule = $usePantry
            ? '- Prioriza la despensa; usa sus productos sin cambiar la identidad tradicional del plato.'
            : '- La idea manda. Respeta la receta tradicional y su acompañamiento; no sustituyas arroz, papa o yuca por quinoa solo porque existe en el catálogo.';

        return <<<PROMPT
Eres un cocinero peruano. Propón una receta de cocina peruana usando solo productos del catálogo y devuelve solo JSON válido.

Reglas:
- Usa únicamente `producto_id` del catálogo. No inventes productos ni ids.
{$pantryRule}
- Evita los productos de `no_incluir`.
- Elige una o más `meal_types` de `comidas_activas`, respeta el tiempo máximo y el equipamiento disponible.
- `instructions` debe traer los pasos de preparación en texto claro, listos para editar.
- `diet_tags` solo acepta `vegetarian`, `vegan`, `high_protein` o `light`; si el perfil es vegetariano o vegano, incluye la etiqueta correspondiente.
- `quantity` debe ser mayor a 0 y `unit` compatible con la unidad del producto.
- Devuelve `course` como `main` para un plato o `drink` para una bebida.
- No expliques nada fuera del JSON.

Contexto:
```json
{$this->json($context)}
```

Formato exacto:
{"recipe":{"name":"Pescado con verduras al vapor","meal_types":["lunch"],"course":"main","prep_minutes":25,"equipment":["stove"],"diet_tags":["light"],"instructions":"1. ...","ingredients":[{"producto_id":1,"quantity":0.4,"unit":"kg"}]}}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
