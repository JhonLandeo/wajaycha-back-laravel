<?php

declare(strict_types=1);

use App\Models\MealPlanEntry;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Recipe, 1: Product}
 */
function menuRecipe(int $userId, string $meal = 'lunch', float $baseServings = 2): array
{
    $product = Product::factory()->create(['unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => 'Arroz con pollo '.fake()->unique()->word(),
        'meal_types' => [$meal],
        'base_servings' => $baseServings,
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

it('rejects unauthenticated access', function () {
    $this->getJson('/api/shopping/menu')->assertStatus(401);
});

it('returns default settings and creates them on the first read', function () {
    [$user, $headers] = $this->userWithAuth();

    $this->getJson('/api/shopping/menu?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonPath('data.settings.household_size', 2)
        ->assertJsonPath('data.settings.meal_types', ['breakfast', 'lunch', 'dinner'])
        ->assertJsonPath('data.settings.plan_weekdays', [1, 2, 3, 4, 5, 6, 7])
        ->assertJsonPath('data.week.starts_on', '2026-11-09')
        ->assertJsonPath('data.week.ends_on', '2026-11-15');

    expect(\App\Models\MealPlanSettings::where('user_id', $user->id)->exists())->toBeTrue();
});

it('updates the household settings', function () {
    [, $headers] = $this->userWithAuth();

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 3,
        'meal_types' => ['lunch', 'dinner'],
        'plan_weekdays' => [1, 2, 3, 4, 5],
        'max_prep_minutes' => 30,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.household_size', 3)
        ->assertJsonPath('data.meal_types', ['lunch', 'dinner'])
        ->assertJsonPath('data.plan_weekdays', [1, 2, 3, 4, 5])
        ->assertJsonPath('data.max_prep_minutes', 30);
});

it('saves a recipe entry and a leftover that consumes its extra portions', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe, $product] = menuRecipe($user->id);

    $source = $this->putJson('/api/shopping/menu/entries', [
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $recipe->id,
        'servings' => 2,
        'cook_servings' => 4,
        'status' => 'cooked',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.recipe.name', $recipe->name)
        ->assertJsonPath('data.status', 'cooked')
        ->assertJsonPath('data.status_label', 'Cociné')
        ->assertJsonPath('data.cook_servings', 4)
        ->json('data');

    $this->putJson('/api/shopping/menu/entries', [
        'plan_date' => '2026-11-10',
        'meal_type' => 'lunch',
        'kind' => 'leftover',
        'leftover_of_entry_id' => $source['id'],
        'servings' => 2,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.kind', 'leftover')
        ->assertJsonPath('data.leftover_from.id', $source['id'])
        ->assertJsonPath('data.leftover_from.recipe_name', $recipe->name);

    $this->getJson('/api/shopping/menu/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.lines.0.product_id', $product->id)
        ->assertJsonPath('data.lines.0.to_buy_quantity', 1);
});

it('rejects a recipe for the wrong meal and a leftover without enough portions', function () {
    [$user, $headers] = $this->userWithAuth();
    [$lunchRecipe] = menuRecipe($user->id);

    $this->putJson('/api/shopping/menu/entries', [
        'plan_date' => '2026-11-09',
        'meal_type' => 'dinner',
        'kind' => 'recipe',
        'recipe_id' => $lunchRecipe->id,
        'servings' => 2,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('recipe_id');

    $source = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $lunchRecipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'servings' => 4,
        'cook_servings' => 4,
    ]);

    $this->putJson('/api/shopping/menu/entries', [
        'plan_date' => '2026-11-10',
        'meal_type' => 'lunch',
        'kind' => 'leftover',
        'leftover_of_entry_id' => $source->id,
        'servings' => 2,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('servings');
});

it('does not count entries on days that are not planned', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe] = menuRecipe($user->id);
    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['breakfast', 'lunch', 'dinner'],
        'plan_weekdays' => [1, 2, 3, 4, 5],
    ], $headers)->assertOk();

    $this->putJson('/api/shopping/menu/entries', [
        'plan_date' => '2026-11-14',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $recipe->id,
        'servings' => 2,
    ], $headers)->assertOk();

    $this->getJson('/api/shopping/menu/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonCount(0, 'data.lines');
});

it('deletes an entry and its leftover children', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe] = menuRecipe($user->id);
    $source = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'servings' => 2,
        'cook_servings' => 4,
    ]);
    $leftover = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-10',
        'meal_type' => 'lunch',
        'kind' => 'leftover',
        'leftover_of_entry_id' => $source->id,
        'servings' => 2,
        'cook_servings' => 0,
    ]);

    $this->deleteJson("/api/shopping/menu/entries/{$source->id}", [], $headers)->assertOk();

    expect(MealPlanEntry::find($source->id))->toBeNull()
        ->and(MealPlanEntry::find($leftover->id))->toBeNull();
});
