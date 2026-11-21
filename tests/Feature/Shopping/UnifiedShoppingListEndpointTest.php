<?php

declare(strict_types=1);

use App\Models\ConsumptionHabit;
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Recipe, 1: Product}
 */
function unifiedRecipe(int $userId): array
{
    $product = Product::factory()->create(['unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => 'Arroz con pollo '.fake()->unique()->word(),
        'meal_types' => ['lunch'],
        'course' => 'main',
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

it('rejects unauthenticated reads and checks', function () {
    $this->getJson('/api/shopping/shopping-list')->assertStatus(401);
    $this->patchJson('/api/shopping/shopping-list/checks', [
        'week_start' => '2026-11-09',
        'product_id' => 1,
        'checked' => true,
    ])->assertStatus(401);
});

it('merges menu and habits and subtracts the pantry once', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = unifiedRecipe($user->id);

    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'weekly_quantity' => 1.0,
        'unit' => 'kg',
    ]);
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 0.25,
        'unit' => 'kg',
    ]);
    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'status' => 'planned',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $lines = $this->getJson('/api/shopping/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->json('data.lines');

    expect($lines)->toHaveCount(1)
        ->and((float) $lines[0]['needed_quantity'])->toBe(1.5)
        ->and((float) $lines[0]['to_buy_quantity'])->toBe(1.25)
        ->and($lines[0]['checked'])->toBeFalse();
});

it('filters by source without mixing the other one', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $menuProduct] = unifiedRecipe($user->id);
    $habitProduct = Product::factory()->create(['unit' => 'kg']);

    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $habitProduct->id,
        'weekly_quantity' => 1.0,
        'unit' => 'kg',
    ]);
    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'status' => 'planned',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $menuOnly = $this->getJson('/api/shopping/shopping-list?week_start=2026-11-09&sources=menu', $headers)
        ->assertOk()
        ->json('data.lines');
    $habitOnly = $this->getJson('/api/shopping/shopping-list?week_start=2026-11-09&sources=habits', $headers)
        ->assertOk()
        ->json('data.lines');

    expect($menuOnly)->toHaveCount(1)
        ->and($menuOnly[0]['product_id'])->toBe($menuProduct->id)
        ->and($habitOnly)->toHaveCount(1)
        ->and($habitOnly[0]['product_id'])->toBe($habitProduct->id);
});

it('persists a check per week and never leaks another user mark', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'weekly_quantity' => 1.0,
        'unit' => 'kg',
    ]);

    $other = User::factory()->create();
    ShoppingListCheck::factory()->create([
        'user_id' => $other->id,
        'week_start' => '2026-11-09',
        'product_id' => $product->id,
    ]);

    $before = $this->getJson('/api/shopping/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->json('data.lines.0.checked');

    $this->patchJson('/api/shopping/shopping-list/checks', [
        'week_start' => '2026-11-09',
        'product_id' => $product->id,
        'checked' => true,
    ], $headers)->assertOk();

    $thisWeek = $this->getJson('/api/shopping/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->json('data.lines.0.checked');
    $nextWeek = $this->getJson('/api/shopping/shopping-list?week_start=2026-11-16', $headers)
        ->assertOk()
        ->json('data.lines.0.checked');

    $this->patchJson('/api/shopping/shopping-list/checks', [
        'week_start' => '2026-11-09',
        'product_id' => $product->id,
        'checked' => false,
    ], $headers)->assertOk();

    $after = $this->getJson('/api/shopping/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->json('data.lines.0.checked');

    expect($before)->toBeFalse()
        ->and($thisWeek)->toBeTrue()
        ->and($nextWeek)->toBeFalse()
        ->and($after)->toBeFalse();
});

it('rejects a checked product that the caller cannot see', function () {
    [$user, $headers] = $this->userWithAuth();
    $other = User::factory()->create();
    $private = Product::factory()->create(['user_id' => $other->id, 'unit' => 'kg']);

    $this->patchJson('/api/shopping/shopping-list/checks', [
        'week_start' => '2026-11-09',
        'product_id' => $private->id,
        'checked' => true,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('product_id');
});
