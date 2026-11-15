<?php

declare(strict_types=1);

use App\Models\FoodBudget;
use App\Models\MealPlanEntry;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Recipe, 1: Product}
 */
function menuRecipe(int $userId, string $meal = 'lunch', float $baseServings = 2, string $course = 'main'): array
{
    $product = Product::factory()->create(['unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => 'Arroz con pollo '.fake()->unique()->word(),
        'meal_types' => [$meal],
        'course' => $course,
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

it('persists the menu strategy', function () {
    [, $headers] = $this->userWithAuth();

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3],
        'menu_strategy' => 'save_money',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.menu_strategy', 'save_money')
        ->assertJsonPath('data.menu_strategy_label', 'Ahorrar');
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
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.recipe.name', $recipe->name)
        ->assertJsonPath('data.status', 'planned')
        ->assertJsonPath('data.status_label', 'Pendiente')
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
it('marks the status of an entry from a one-tap endpoint', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe] = menuRecipe($user->id);
    $entry = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
    ]);

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'cooked'], $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'cooked')
        ->assertJsonPath('data.status_label', 'Cociné');

    $this->patchJson("/api/shopping/menu/entries/{$entry->id}/status", ['status' => 'invented'], $headers)
        ->assertStatus(422);
});

it('compares the menu estimate with the real food spend of the week', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe] = menuRecipe($user->id);
    $category = \App\Models\Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 400,
    ]);
    $budget = FoodBudget::factory()->create([
        'user_id' => $user->id,
        'monthly_amount' => 400,
    ]);
    $budget->categories()->syncWithPivotValues([$category->id], ['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 50,
        'date_operation' => '2026-11-10',
    ]);
    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->getJson('/api/shopping/menu/spending?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonPath('data.actual_spent', 50)
        ->assertJsonPath('data.planned_cost', null)
        ->assertJsonPath('data.difference', null);
});

it('saves a main recipe with a drink and returns the ingredients of both', function () {
    [$user, $headers] = $this->userWithAuth();
    [$mainRecipe, $mainProduct] = menuRecipe($user->id);
    [$drinkRecipe, $drinkProduct] = menuRecipe($user->id, 'lunch', 2, 'drink');

    $this->putJson('/api/shopping/menu/entries', [
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $mainRecipe->id,
        'drink_recipe_id' => $drinkRecipe->id,
        'servings' => 2,
        'cook_servings' => 2,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.recipe.name', $mainRecipe->name)
        ->assertJsonPath('data.recipe.ingredients.0.product_name', $mainProduct->name)
        ->assertJsonPath('data.drink_recipe_id', $drinkRecipe->id)
        ->assertJsonPath('data.drink_recipe.name', $drinkRecipe->name)
        ->assertJsonPath('data.drink_recipe.ingredients.0.product_name', $drinkProduct->name);

    $this->getJson('/api/shopping/menu/shopping-list?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data.lines');
});

it('rejects a drink that does not belong to the meal', function () {
    [$user, $headers] = $this->userWithAuth();
    [$mainRecipe] = menuRecipe($user->id, 'lunch');
    [$dinnerRecipe] = menuRecipe($user->id, 'dinner', 2, 'drink');

    $this->putJson('/api/shopping/menu/entries', [
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $mainRecipe->id,
        'drink_recipe_id' => $dinnerRecipe->id,
        'servings' => 2,
        'cook_servings' => 2,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('drink_recipe_id');
});

it('persists the diet profile and the weekly limit of AI recipes', function () {
    [, $headers] = $this->userWithAuth();

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3],
        'diet_profile' => 'vegetarian',
        'new_recipes_per_week' => 2,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.diet_profile', 'vegetarian')
        ->assertJsonPath('data.diet_profile_label', 'Vegetariano')
        ->assertJsonPath('data.new_recipes_per_week', 2);
});
