<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Enums\DietProfile;
use App\Enums\Unit;
use App\Models\MealPlanSettings;
use App\Models\Product;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\AI\OpenCodeGateway;
use App\Services\Shopping\AiRecipeDraftValidator;
use App\Services\Shopping\UnitConverter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
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
    private const MAX_NEW_PRODUCTS = 3;

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
        $existingByName = [];

        foreach ($products as $product) {
            $productsById[(int) $product->id] = $product;
            $existingByName[$this->fold((string) $product->name)] = $product;
        }

        $createdProducts = 0;
        $recipe = $response['recipe'];
        $ingredients = $recipe['ingredients'] ?? [];

        if (! is_array($ingredients)) {
            return null;
        }

        $recipe['ingredients'] = $this->resolveNewProducts(
            $ingredients,
            $userId,
            $settings,
            $productsById,
            $existingByName,
            $createdProducts,
        );

        if ($recipe['ingredients'] === null) {
            return null;
        }

        $existingNames = [];
        $draft = $this->validator->validate($recipe, $settings, $productsById, $existingNames);

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
     * @param  array<int, mixed>  $ingredients
     * @param  array<int, Product>  $productsById
     * @param  array<string, Product>  $existingByName
     * @return array<int, mixed>|null
     */
    private function resolveNewProducts(
        array $ingredients,
        int $userId,
        MealPlanSettings $settings,
        array &$productsById,
        array &$existingByName,
        int &$createdProducts,
    ): ?array {
        $resolved = [];

        foreach ($ingredients as $ingredient) {
            if (! is_array($ingredient)) {
                return null;
            }

            $new = $ingredient['nuevo_producto'] ?? null;
            $productId = (int) ($ingredient['product_id'] ?? $ingredient['producto_id'] ?? 0);

            if (is_array($new) && ($productId <= 0 || ! isset($productsById[$productId]))) {
                $name = trim((string) ($new['name'] ?? ''));
                $unit = Unit::tryFrom((string) ($new['unit'] ?? ''));

                if ($name === '' || mb_strlen($name) > 255 || $unit === null) {
                    return null;
                }

                $folded = $this->fold($name);

                foreach ($settings->excluded_terms ?? [] as $term) {
                    if (str_contains($folded, $this->fold((string) $term))) {
                        return null;
                    }
                }

                $product = $existingByName[$folded] ?? null;

                if ($product === null) {
                    if ($createdProducts >= self::MAX_NEW_PRODUCTS) {
                        return null;
                    }

                    try {
                        $product = $this->shoppingRepository->createProduct($userId, $name, $unit->value);
                    } catch (Throwable $e) {
                        report($e);

                        return null;
                    }

                    $createdProducts++;
                    $existingByName[$folded] = $product;
                }

                $productId = (int) $product->id;
                $productsById[$productId] = $product;

                $ingredientUnit = Unit::tryFrom((string) ($ingredient['unit'] ?? ''));
                $productUnit = Unit::tryFrom((string) $product->unit);
                $quantity = (float) ($ingredient['quantity'] ?? 0);

                if ($ingredientUnit === null
                    || $productUnit === null
                    || $quantity <= 0.0
                    || ! UnitConverter::compatible($ingredientUnit->value, $productUnit->value)) {
                    return null;
                }

                unset($ingredient['nuevo_producto']);
                $ingredient['product_id'] = $productId;
                $ingredient['quantity'] = UnitConverter::toCanonical(
                    $quantity,
                    $ingredientUnit->value,
                    $productUnit->value,
                );
                $ingredient['unit'] = $productUnit->value;
            }

            $resolved[] = $ingredient;
        }

        return $resolved;
    }

    private function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
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
- Usa únicamente `producto_id` del catálogo. No inventes ids.
- Si la idea pide un producto que no está en `productos`, no lo sustituyas ni cambies el nombre del plato: usa `nuevo_producto` con `name` y `unit` (unidad compatible con la cantidad). El backend lo creará como producto privado. Usa como máximo 3 productos nuevos y solo los que pida la idea.
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
{"recipe":{"name":"Mazamorra de piña","meal_types":["lunch"],"course":"main","prep_minutes":25,"equipment":["stove"],"diet_tags":["vegetarian"],"instructions":"1. ...","ingredients":[{"nuevo_producto":{"name":"Piña","unit":"unidad"},"quantity":1,"unit":"unidad"}]}}
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
