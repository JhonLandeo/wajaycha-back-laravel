<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\PantryStock;
use App\Models\ConsumptionHabit;
use App\Models\PantryItem;
use App\Models\Product;

interface ShoppingRepositoryContract
{
    /**
     * The catalogue a user sees: the shared global rows plus their own
     * private additions (design.md D3). $userId is required so a caller
     * cannot list every private product in the database by omitting it.
     *
     * @return Product[]
     */
    public function listProductsFor(int $userId): array;

    /**
     * Creates a user's own private addition. Always owned (`user_id` set)
     * and never carries a `slug` — that column identifies seeded rows only.
     */
    public function createProduct(int $userId, string $name, string $unit): Product;

    /**
     * @param  array<string, mixed>  $data  Already carries `user_id` — set by
     *                                      the caller, not derived here.
     */
    public function createPantryItem(array $data): PantryItem;

    /**
     * Every pantry item owned by $userId, expired ones included — expiry only
     * excludes a row from AVAILABILITY (spec.md), it never hides the row
     * itself, and this list is the CRUD read, not the weekly-plan read.
     *
     * @return PantryItem[]
     */
    public function listPantryItemsFor(int $userId): array;

    /**
     * $userId is an AUTHORIZATION BOUNDARY, not a convenience filter
     * (design.md D8, no route model binding) — a caller that cannot supply
     * the owner has no business calling this method.
     */
    public function findPantryItem(int $id, int $userId): ?PantryItem;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePantryItem(PantryItem $pantryItem, array $data): bool;

    public function deletePantryItem(PantryItem $pantryItem): bool;

    /**
     * Upserts by (user_id, product_id) — declaring the same product twice
     * updates the existing habit rather than creating a duplicate (spec.md
     * "Declared consumption habit", unq_consumption_habits_user_id_product_id
     * is the arbiter, reused from slice 2's pattern).
     *
     * @param  array<string, mixed>  $data  Already carries `user_id`.
     */
    public function createConsumptionHabit(array $data): ConsumptionHabit;

    /**
     * Active habits only (design.md M3, idx_consumption_habits_user_active) —
     * the CRUD read for this slice; the weekly-plan read (slice 5) will reuse
     * this same method rather than a separate one.
     *
     * @return ConsumptionHabit[]
     */
    public function activeHabitsFor(int $userId): array;

    /**
     * $userId is an AUTHORIZATION BOUNDARY, not a convenience filter
     * (design.md D8, no route model binding) — a caller that cannot supply
     * the owner has no business calling this method.
     */
    public function findConsumptionHabit(int $id, int $userId): ?ConsumptionHabit;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateConsumptionHabit(ConsumptionHabit $consumptionHabit, array $data): bool;

    public function deleteConsumptionHabit(ConsumptionHabit $consumptionHabit): bool;

    /**
     * Active habits translated into the plain value `ShoppingPlanService`
     * accepts (design.md "Inputs" — a DTO may not import `App\Models`, and
     * the pure service may not either).
     *
     * Deliberately a SEPARATE method from {@see activeHabitsFor()}, not a
     * changed return type on it: that method still serves
     * `ConsumptionHabitController@index`'s CRUD read, which needs the row's
     * own id (to `PUT`/`DELETE` it) that a `ConsumptionHabitLine` value
     * object has no reason to carry. design.md's data-flow diagram names
     * this capability `activeHabitsFor($userId) → ConsumptionHabitLine[]`;
     * one method name cannot honestly return two incompatible shapes for
     * two different callers, so the plan-only read is named distinctly.
     *
     * @return ConsumptionHabitLine[]
     */
    public function activeHabitLinesFor(int $userId): array;

    /**
     * Every pantry row for $userId, expired ones included — the same
     * population `listPantryItemsFor()` reads, translated into the plain
     * value the weekly-plan computation accepts (design.md "Inputs").
     * `ShoppingPlanService` decides what expiry and unit mismatch MEAN;
     * this method only detaches the row from Eloquent.
     *
     * @return PantryStock[]
     */
    public function pantryStockFor(int $userId): array;
}
