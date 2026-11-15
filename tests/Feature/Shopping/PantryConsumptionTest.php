<?php

declare(strict_types=1);

use App\Models\MealPlanEntry;
use App\Models\PantryConsumption;
use App\Models\PantryItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Recipe, 1: Product}
 */
function pantryConsumptionRecipe(int $userId, string $course = 'main'): array
{
    $product = Product::factory()->create(['name' => 'Arroz '.fake()->unique()->word(), 'unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => 'Arroz con pollo '.fake()->unique()->word(),
        'meal_types' => ['lunch'],
        'course' => $course,
        'base_servings' => 2,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $product->id,
        'quantity' => 0.5,
        'unit' => 'kg',
        'is_optional' => false,
    ]);

    return [$recipe, $product];
}

function pantryLot(int $userId, Product $product, float $quantity, string $expiresOn): PantryItem
{
    return PantryItem::factory()->create([
        'user_id' => $userId,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'unit' => 'kg',
        'expires_on' => $expiresOn,
    ]);
}

function mealRequest(int $recipeId, string $date, int $cookServings = 2): array
{
    return [
        'plan_date' => $date,
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $recipeId,
        'servings' => 2,
        'cook_servings' => $cookServings,
    ];
}

it('consumes pantry FIFO by expiry when a meal is marked cooked', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    $later = pantryLot($user->id, $product, 0.5, '2026-11-20');
    $sooner = pantryLot($user->id, $product, 0.6, '2026-11-15');

    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'servings' => 2,
        'cook_servings' => 4,
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    $rows = PantryConsumption::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->pantry_item_id)->toBe($sooner->id)
        ->and((float) $rows[0]->quantity)->toBe(0.6)
        ->and((int) $rows[1]->pantry_item_id)->toBe($later->id)
        ->and((float) $rows[1]->quantity)->toBe(0.4)
        ->and($rows[0]->consumed_on->toDateString())->toBe('2026-11-09')
        ->and($rows[0]->reason)->toBe('cooked');
});

it('does not buy ingredients for a meal already cooked and nets the consumed stock', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    pantryLot($user->id, $product, 0.1, '2026-11-20');

    $this->putJson('/api/shopping/menu/entries', mealRequest($recipe->id, '2026-11-09', 2), $headers)
        ->assertOk();

    $entry = MealPlanEntry::query()->where('user_id', $user->id)->firstOrFail();
    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    // The cooked entry is no longer a need; the stock it consumed is gone.
    $this->getJson('/api/shopping/menu/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonCount(0, 'data.lines');

    $this->putJson('/api/shopping/menu/entries', mealRequest($recipe->id, '2026-11-10', 2), $headers)
        ->assertOk();

    $this->getJson('/api/shopping/menu/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.lines.0.product_id', $product->id)
        ->assertJsonPath('data.lines.0.needed_quantity', 0.5)
        ->assertJsonPath('data.lines.0.available_quantity', 0)
        ->assertJsonPath('data.lines.0.to_buy_quantity', 0.5);
});

it('restores the pantry when the meal is no longer cooked', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    pantryLot($user->id, $product, 1.0, '2026-11-20');

    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    expect(PantryConsumption::query()->count())->toBe(1);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'planned'], $headers)
        ->assertOk();

    expect(PantryConsumption::query()->count())->toBe(0);
});

it('does not duplicate consumption when the same meal is marked cooked again', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    pantryLot($user->id, $product, 1.0, '2026-11-20');

    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();
    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    expect(PantryConsumption::query()->count())->toBe(1)
        ->and((float) PantryConsumption::query()->sum('quantity'))->toBe(0.5);
});

it('consumes when a cooked entry is written through the entry endpoint', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    pantryLot($user->id, $product, 1.0, '2026-11-20');

    $payload = mealRequest($recipe->id, '2026-11-09', 2);
    $payload['status'] = 'cooked';

    $this->putJson('/api/shopping/menu/entries', $payload, $headers)->assertOk();

    expect(PantryConsumption::query()->count())->toBe(1);
});

it('never consumes a lot whose unit does not match the product', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 500,
        'unit' => 'g',
        'expires_on' => '2026-11-20',
    ]);

    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    expect(PantryConsumption::query()->count())->toBe(0);
});

it('never consumes an expired lot', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    $expired = pantryLot($user->id, $product, 2.0, '2026-11-08');
    $valid = pantryLot($user->id, $product, 1.0, '2026-11-20');

    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    $rows = PantryConsumption::query()->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->pantry_item_id)->toBe($valid->id)
        ->and((float) $rows[0]->quantity)->toBe(0.5);
});

it('deletes consumption rows when the cooked entry is deleted', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = pantryConsumptionRecipe($user->id);

    pantryLot($user->id, $product, 1.0, '2026-11-20');

    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    $this->deleteJson("/api/shopping/menu/entries/{$entry->id}", [], $headers)->assertOk();

    expect(PantryConsumption::query()->count())->toBe(0)
        ->and(PantryItem::query()->count())->toBe(1);
});

it('consumes both the main recipe and its drink when the meal is cooked', function () {
    [$user, $headers] = $this->userWithAuth();
    [$mainRecipe, $mainProduct] = pantryConsumptionRecipe($user->id);
    [$drinkRecipe, $drinkProduct] = pantryConsumptionRecipe($user->id, 'drink');

    pantryLot($user->id, $mainProduct, 1.0, '2026-11-20');
    pantryLot($user->id, $drinkProduct, 1.0, '2026-11-20');

    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $mainRecipe->id,
        'drink_recipe_id' => $drinkRecipe->id,
        'plan_date' => '2026-11-09',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk();

    $rows = PantryConsumption::query()->orderBy('product_id')->get();

    expect($rows)->toHaveCount(2)
        ->and((float) $rows[0]->quantity)->toBe(0.5)
        ->and((float) $rows[1]->quantity)->toBe(0.5)
        ->and($rows->pluck('product_id')->all())->toEqualCanonicalizing([$mainProduct->id, $drinkProduct->id]);
});
