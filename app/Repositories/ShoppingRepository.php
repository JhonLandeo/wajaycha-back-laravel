<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\PantryStock;
use App\Enums\Unit;
use App\Models\ConsumptionHabit;
use App\Models\PantryConsumption;
use App\Models\PantryItem;
use App\Models\Product;
use App\Models\ShoppingListCheck;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use Carbon\CarbonImmutable;

final class ShoppingRepository implements ShoppingRepositoryContract
{
    public function listProductsFor(int $userId): array
    {
        return Product::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $userId))
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function createProduct(int $userId, string $name, string $unit): Product
    {
        return Product::create([
            'user_id' => $userId,
            'slug' => null,
            'name' => $name,
            'unit' => $unit,
            'is_active' => true,
        ]);
    }

    public function createPantryItem(array $data): PantryItem
    {
        /** @var PantryItem */
        return PantryItem::query()->create($data);
    }

    public function listPantryItemsFor(int $userId): array
    {
        return PantryItem::query()
            ->with('product')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->all();
    }

    public function findPantryItem(int $id, int $userId): ?PantryItem
    {
        /** @var PantryItem|null $item */
        $item = PantryItem::query()->whereKey($id)->where('user_id', $userId)->first();

        return $item;
    }

    public function updatePantryItem(PantryItem $pantryItem, array $data): bool
    {
        return $pantryItem->update($data);
    }

    public function deletePantryItem(PantryItem $pantryItem): bool
    {
        return (bool) $pantryItem->delete();
    }

    public function createConsumptionHabit(array $data): ConsumptionHabit
    {
        /** @var ConsumptionHabit */
        return ConsumptionHabit::query()->updateOrCreate(
            [
                'user_id' => $data['user_id'],
                'product_id' => $data['product_id'],
            ],
            [
                'weekly_quantity' => $data['weekly_quantity'],
                'unit' => $data['unit'],
            ]
        );
    }

    public function activeHabitsFor(int $userId): array
    {
        return ConsumptionHabit::query()
            ->with('product')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->all();
    }

    public function findConsumptionHabit(int $id, int $userId): ?ConsumptionHabit
    {
        /** @var ConsumptionHabit|null $habit */
        $habit = ConsumptionHabit::query()->whereKey($id)->where('user_id', $userId)->first();

        return $habit;
    }

    public function updateConsumptionHabit(ConsumptionHabit $consumptionHabit, array $data): bool
    {
        return $consumptionHabit->update($data);
    }

    public function deleteConsumptionHabit(ConsumptionHabit $consumptionHabit): bool
    {
        return (bool) $consumptionHabit->delete();
    }

    public function activeHabitLinesFor(int $userId): array
    {
        return array_map(
            static fn (ConsumptionHabit $habit): ConsumptionHabitLine => new ConsumptionHabitLine(
                productId: (int) $habit->product_id,
                productName: (string) $habit->product?->name,
                // Unit::from(), not tryFrom(): a habit's own unit is
                // validated at write time (StoreConsumptionHabitRequest)
                // and has no unmatched-unit concept of its own — only a
                // PantryStock row can arrive with an unrecognised unit.
                unit: Unit::from((string) $habit->unit),
                weeklyQuantity: (float) $habit->weekly_quantity,
            ),
            $this->activeHabitsFor($userId)
        );
    }

    public function pantryStockFor(int $userId): array
    {
        $consumed = $this->consumedQuantityByPantryItem($userId);
        $stocks = [];

        foreach ($this->listPantryItemsFor($userId) as $item) {
            $available = round((float) $item->quantity - ($consumed[(int) $item->id] ?? 0.0), 3);

            // A lot consumed to zero is no longer availability, but the row and
            // its history stay (ADR-0020: nothing is deleted, only derived).
            if ($available <= 0.0) {
                continue;
            }

            $expiresOn = $item->expires_on;

            $stocks[] = new PantryStock(
                productId: (int) $item->product_id,
                unit: Unit::tryFrom((string) $item->unit),
                quantity: $available,
                expiresOn: $expiresOn !== null ? CarbonImmutable::parse($expiresOn) : null,
                productName: (string) $item->product?->name,
                acquiredOn: $item->acquired_on !== null ? CarbonImmutable::parse($item->acquired_on) : null,
                shelfLifeDays: $item->product?->shelf_life_days,
            );
        }

        return $stocks;
    }

    public function pantryItemsForConsumption(int $userId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return PantryItem::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $productIds)
            ->get()
            ->all();
    }

    public function consumedQuantityByPantryItem(int $userId): array
    {
        return PantryConsumption::query()
            ->where('user_id', $userId)
            ->selectRaw('pantry_item_id, SUM(quantity) as consumed')
            ->groupBy('pantry_item_id')
            ->pluck('consumed', 'pantry_item_id')
            ->map(static fn ($quantity): float => (float) $quantity)
            ->all();
    }

    public function insertPantryConsumptions(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        foreach ($rows as $index => $row) {
            $rows[$index]['created_at'] = $now;
            $rows[$index]['updated_at'] = $now;
        }

        PantryConsumption::query()->insert($rows);
    }

    public function deletePantryConsumptionsForEntry(int $mealPlanEntryId): void
    {
        PantryConsumption::query()
            ->where('meal_plan_entry_id', $mealPlanEntryId)
            ->delete();
    }

    public function checkedProductIdsFor(int $userId, string $weekStart): array
    {
        return ShoppingListCheck::query()
            ->where('user_id', $userId)
            ->where('week_start', $weekStart)
            ->pluck('product_id')
            ->map(static fn ($productId): int => (int) $productId)
            ->all();
    }

    public function setShoppingListCheck(int $userId, string $weekStart, int $productId, bool $checked): void
    {
        if ($checked) {
            ShoppingListCheck::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'week_start' => $weekStart,
                    'product_id' => $productId,
                ],
                [],
            );

            return;
        }

        ShoppingListCheck::query()
            ->where('user_id', $userId)
            ->where('week_start', $weekStart)
            ->where('product_id', $productId)
            ->delete();
    }
}
