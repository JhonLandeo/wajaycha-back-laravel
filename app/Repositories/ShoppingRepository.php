<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\PantryStock;
use App\Enums\Unit;
use App\Models\ConsumptionHabit;
use App\Models\PantryItem;
use App\Models\Product;
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
        return array_map(
            static function (PantryItem $item): PantryStock {
                $expiresOn = $item->expires_on;

                return new PantryStock(
                    productId: (int) $item->product_id,
                    unit: Unit::tryFrom((string) $item->unit),
                    quantity: (float) $item->quantity,
                    expiresOn: $expiresOn !== null ? CarbonImmutable::parse($expiresOn) : null,
                );
            },
            $this->listPantryItemsFor($userId)
        );
    }
}
