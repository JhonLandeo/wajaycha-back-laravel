<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\Unit;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Shopping\UnitConverter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mantiene `pantry_consumptions` en sintonía con el estado de una comida.
 *
 * Marcar `cooked` consume los ingredientes de la receta escalados por
 * `cook_servings`, en orden FIFO por vencimiento y solo si la unidad coincide.
 * Cualquier otro estado borra los consumos de la entrada y devuelve el stock.
 * La operación es idempotente: primero borra y luego recalcula, así editar una
 * comida ya cocinada no duplica el descuento.
 *
 * Nunca escribe en `pantry_items`: el disponible es adquisiciones menos
 * consumos (ADR-0020).
 */
final class SyncPantryConsumptionAction
{
    public function __construct(
        private readonly ShoppingRepositoryContract $shoppingRepository,
    ) {}

    public function execute(MealPlanEntry $entry): void
    {
        $entry->loadMissing(['recipe.ingredients.product']);

        DB::transaction(function () use ($entry): void {
            $this->shoppingRepository->deletePantryConsumptionsForEntry((int) $entry->id);

            if ($entry->status !== MealEntryStatus::Cooked
                || $entry->kind !== MealEntryKind::Recipe
                || $entry->recipe === null
                || (int) $entry->cook_servings < 1) {
                return;
            }

            $requirements = $this->requirements($entry);

            if ($requirements === []) {
                return;
            }

            $lots = $this->lotsByProduct($entry, array_keys($requirements));
            $rows = $this->consume($entry, $requirements, $lots);

            $this->shoppingRepository->insertPantryConsumptions($rows);
        });
    }

    /**
     * Ingredientes requeridos por producto, ya escalados y convertidos a la
     * unidad canónica del producto.
     *
     * @return array<int, array{unit: string, quantity: float}>
     */
    private function requirements(MealPlanEntry $entry): array
    {
        $recipe = $entry->recipe;

        if ($recipe === null) {
            return [];
        }

        $scale = (int) $entry->cook_servings / max(1, (int) $recipe->base_servings);
        $requirements = [];

        foreach ($recipe->ingredients as $ingredient) {
            if ($ingredient->is_optional) {
                continue;
            }

            $product = $ingredient->product;

            $productUnit = Unit::tryFrom((string) $product->unit);
            $ingredientUnit = Unit::tryFrom((string) $ingredient->unit);

            if ($productUnit === null || $ingredientUnit === null) {
                continue;
            }

            try {
                $quantity = UnitConverter::toCanonical(
                    (float) $ingredient->quantity * $scale,
                    $ingredientUnit->value,
                    $productUnit->value,
                );
            } catch (InvalidArgumentException) {
                continue;
            }

            $productId = (int) $product->id;
            $requirements[$productId] ??= ['unit' => $productUnit->value, 'quantity' => 0.0];
            $requirements[$productId]['quantity'] = round($requirements[$productId]['quantity'] + $quantity, 3);
        }

        return $requirements;
    }

    /**
     * Lotes disponibles por producto, con lo consumido ya restado y sin los
     * vencidos a la fecha de la comida.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, array{item: \App\Models\PantryItem, remaining: float}>>
     */
    private function lotsByProduct(MealPlanEntry $entry, array $productIds): array
    {
        $consumed = $this->shoppingRepository->consumedQuantityByPantryItem((int) $entry->user_id);
        $referenceDate = CarbonImmutable::parse($entry->plan_date->toDateString(), 'America/Lima');
        $byProduct = [];

        foreach ($this->shoppingRepository->pantryItemsForConsumption((int) $entry->user_id, $productIds) as $item) {
            $remaining = round((float) $item->quantity - ($consumed[(int) $item->id] ?? 0.0), 3);

            if ($remaining <= 0.0) {
                continue;
            }

            if ($item->expires_on !== null && $item->expires_on->lt($referenceDate)) {
                continue;
            }

            $byProduct[(int) $item->product_id][] = ['item' => $item, 'remaining' => $remaining];
        }

        foreach ($byProduct as &$lots) {
            usort($lots, static function (array $a, array $b): int {
                $expiry = ($a['item']->expires_on?->toDateString() ?? '9999-12-31')
                    <=> ($b['item']->expires_on?->toDateString() ?? '9999-12-31');

                return $expiry
                    ?: (($a['item']->acquired_on?->toDateString() ?? '9999-12-31')
                        <=> ($b['item']->acquired_on?->toDateString() ?? '9999-12-31'))
                    ?: ((int) $a['item']->id <=> (int) $b['item']->id);
            });
        }
        unset($lots);

        return $byProduct;
    }

    /**
     * @param  array<int, array{unit: string, quantity: float}>  $requirements
     * @param  array<int, array<int, array{item: \App\Models\PantryItem, remaining: float}>>  $lots
     * @return array<int, array<string, mixed>>
     */
    private function consume(MealPlanEntry $entry, array $requirements, array $lots): array
    {
        $rows = [];

        foreach ($requirements as $productId => $need) {
            $remainingNeed = $need['quantity'];

            foreach ($lots[$productId] ?? [] as $lot) {
                if ($remainingNeed <= 0.0) {
                    break;
                }

                /** @var \App\Models\PantryItem $item */
                $item = $lot['item'];

                if ((string) $item->unit !== $need['unit']) {
                    continue;
                }

                $take = round(min($remainingNeed, $lot['remaining']), 3);

                if ($take <= 0.0) {
                    continue;
                }

                $rows[] = [
                    'user_id' => (int) $entry->user_id,
                    'pantry_item_id' => (int) $item->id,
                    'meal_plan_entry_id' => (int) $entry->id,
                    'product_id' => (int) $productId,
                    'quantity' => $take,
                    'unit' => $need['unit'],
                    'consumed_on' => $entry->plan_date->toDateString(),
                    'reason' => 'cooked',
                ];

                $remainingNeed = round($remainingNeed - $take, 3);
            }
        }

        return $rows;
    }
}
