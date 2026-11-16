<?php

declare(strict_types=1);

use App\Models\MealPlanEntry;
use App\Models\MenuChatMessage;
use App\Models\PantryItem;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00', 'America/Lima'));
});

afterEach(function () {
    $this->travelBack();
});

function nutritionRecipe(
    int $userId,
    string $name,
    string $meal,
    array $productNutrition,
    float $quantity = 0.5,
): Recipe {
    $product = Product::factory()->create([
        'name' => $name.' '.fake()->unique()->word(),
        'unit' => 'kg',
        'calories_kcal' => $productNutrition['calories_kcal'],
        'protein_g' => $productNutrition['protein_g'],
        'carbs_g' => $productNutrition['carbs_g'],
        'fat_g' => $productNutrition['fat_g'],
    ]);

    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => $name,
        'meal_types' => [$meal],
        'base_servings' => 2,
        'prep_minutes' => 20,
    ]);

    RecipeIngredient::factory()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'unit' => 'kg',
        'is_optional' => false,
    ]);

    return $recipe;
}

it('calcula la nutrición por porción desde los productos y la expone en el menú', function () {
    [$user, $headers] = $this->userWithAuth();
    $recipe = nutritionRecipe($user->id, 'Arroz con pollo', 'lunch', [
        'calories_kcal' => 3600,
        'protein_g' => 70,
        'carbs_g' => 780,
        'fat_g' => 8,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'daily_calorie_target' => 1800,
        'dinner_carb_limit_g' => 35,
        'dinner_max_prep_minutes' => 20,
        'dinner_calorie_limit' => 600,
        'consider_pantry_in_ai_recipes' => true,
    ], $headers)->assertOk()
        ->assertJsonPath('data.daily_calorie_target', 1800)
        ->assertJsonPath('data.dinner_carb_limit_g', 35)
        ->assertJsonPath('data.dinner_max_prep_minutes', 20)
        ->assertJsonPath('data.dinner_calorie_limit', 600)
        ->assertJsonPath('data.consider_pantry_in_ai_recipes', true);

    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'recipe_id' => $recipe->id,
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->getJson('/api/shopping/menu?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.nutrition.calories_kcal', 900)
        ->assertJsonPath('data.entries.0.recipe.nutrition.carbs_g', 195)
        ->assertJsonPath('data.entries.0.nutrition.calories_kcal', 1800)
        ->assertJsonPath('data.entries.0.nutrition.is_complete', true);
});

it('convierte gramos a kilos antes de multiplicar la nutrición del producto', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create([
        'unit' => 'kg',
        'calories_kcal' => 3600,
        'protein_g' => 70,
        'carbs_g' => 780,
        'fat_g' => 8,
    ]);
    $recipe = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Arroz medido en gramos',
        'meal_types' => ['lunch'],
        'base_servings' => 4,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $product->id,
        'quantity' => 500,
        'unit' => 'g',
        'is_optional' => false,
    ]);

    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'recipe_id' => $recipe->id,
        'servings' => 2,
        'cook_servings' => 2,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $this->getJson('/api/shopping/menu?week_start=2026-11-09', $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.nutrition.calories_kcal', 900)
        ->assertJsonPath('data.entries.0.nutrition.carbs_g', 195);
});

it('prefiere una cena por debajo del límite de carbohidratos cuando hay alternativa', function () {
    [$user, $headers] = $this->userWithAuth();
    nutritionRecipe($user->id, 'Cena con arroz', 'dinner', [
        'calories_kcal' => 3600,
        'protein_g' => 70,
        'carbs_g' => 780,
        'fat_g' => 8,
    ]);
    nutritionRecipe($user->id, 'Cena de pollo', 'dinner', [
        'calories_kcal' => 1650,
        'protein_g' => 270,
        'carbs_g' => 0,
        'fat_g' => 60,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'dinner_carb_limit_g' => 30,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonPath('data.entries.0.recipe.name', 'Cena de pollo');
});

it('ofrece otra receta distinta para una comida del preview', function () {
    [$user, $headers] = $this->userWithAuth();
    $current = nutritionRecipe($user->id, 'Almuerzo actual', 'lunch', [
        'calories_kcal' => 3600,
        'protein_g' => 70,
        'carbs_g' => 780,
        'fat_g' => 8,
    ]);
    $alternative = nutritionRecipe($user->id, 'Almuerzo alternativo', 'lunch', [
        'calories_kcal' => 1650,
        'protein_g' => 270,
        'carbs_g' => 0,
        'fat_g' => 60,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $entry = [
        'id' => 10,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $current->id,
        'recipe' => null,
        'drink_recipe_id' => null,
        'drink_recipe' => null,
        'servings' => 2,
        'cook_servings' => 2,
        'leftover_of_entry_id' => null,
        'notes' => null,
    ];

    $this->postJson('/api/shopping/menu/suggest/alternative', [
        'week_start' => '2026-11-09',
        'date' => '2026-11-09',
        'meal' => 'lunch',
        'entries' => [$entry],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.id', 10)
        ->assertJsonPath('data.recipe_id', $alternative->id)
        ->assertJsonPath('data.recipe.name', 'Almuerzo alternativo');

    expect(MealPlanEntry::query()->count())->toBe(0);
});

it('aplica la alternativa de un hueco vacío aunque su id sea temporal', function () {
    [$user, $headers] = $this->userWithAuth();
    $recipe = nutritionRecipe($user->id, 'Almuerzo alternativo', 'lunch', [
        'calories_kcal' => 1650,
        'protein_g' => 270,
        'carbs_g' => 0,
        'fat_g' => 60,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $alternative = $this->postJson('/api/shopping/menu/suggest/alternative', [
        'week_start' => '2026-11-09',
        'date' => '2026-11-09',
        'meal' => 'lunch',
        'entries' => [],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.id', -1)
        ->assertJsonPath('data.recipe.id', $recipe->id)
        ->json('data');

    $this->postJson('/api/shopping/menu/suggest/apply', [
        'week_start' => '2026-11-09',
        'entries' => [$alternative],
    ], $headers)->assertOk();

    expect(MealPlanEntry::query()->where('user_id', $user->id)->sole()->recipe_id)->toBe($recipe->id);
});

it('ajusta la propuesta por chat sin escribir menú, recetas ni mensajes', function () {
    [$user, $headers] = $this->userWithAuth();
    $current = nutritionRecipe($user->id, 'Almuerzo actual', 'lunch', [
        'calories_kcal' => 3600,
        'protein_g' => 70,
        'carbs_g' => 780,
        'fat_g' => 8,
    ]);
    $alternative = nutritionRecipe($user->id, 'Almuerzo alternativo', 'lunch', [
        'calories_kcal' => 1650,
        'protein_g' => 270,
        'carbs_g' => 0,
        'fat_g' => 60,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $reply = json_encode([
        'reply' => 'Cambio el almuerzo por pollo.',
        'operations' => [[
            'op' => 'swap_recipe',
            'date' => '2026-11-09',
            'meal' => 'lunch',
            'recipe_id' => $alternative->id,
        ]],
    ]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $reply]]]]],
        ]),
    ]);

    $entry = [
        'id' => 10,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $current->id,
        'recipe' => null,
        'drink_recipe_id' => null,
        'drink_recipe' => null,
        'servings' => 2,
        'cook_servings' => 2,
        'leftover_of_entry_id' => null,
        'notes' => null,
    ];

    $this->postJson('/api/shopping/menu/suggest/chat', [
        'week_start' => '2026-11-09',
        'message' => 'cambia el almuerzo por pollo',
        'entries' => [$entry],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.reply', 'Cambio el almuerzo por pollo.')
        ->assertJsonPath('data.entries.0.recipe.name', 'Almuerzo alternativo');

    expect(MealPlanEntry::query()->count())->toBe(0)
        ->and(MenuChatMessage::query()->count())->toBe(0)
        ->and(Recipe::query()->where('name', 'Almuerzo alternativo')->exists())->toBeTrue();
});

it('ofrece como alternativa una receta que usa el producto prioritario de la despensa', function () {
    [$user, $headers] = $this->userWithAuth();
    $current = nutritionRecipe($user->id, 'Almuerzo actual', 'lunch', [
        'calories_kcal' => 3600,
        'protein_g' => 70,
        'carbs_g' => 780,
        'fat_g' => 8,
    ]);
    $papaSeca = Product::factory()->create(['name' => 'Papa seca prioritaria', 'unit' => 'kg']);
    $withPantry = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Almuerzo con papa seca',
        'meal_types' => ['lunch'],
        'base_servings' => 2,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $withPantry->id,
        'product_id' => $papaSeca->id,
        'quantity' => 0.2,
        'unit' => 'kg',
        'is_optional' => false,
    ]);
    nutritionRecipe($user->id, 'Almuerzo sin despensa', 'lunch', [
        'calories_kcal' => 1650,
        'protein_g' => 270,
        'carbs_g' => 0,
        'fat_g' => 60,
    ]);

    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $papaSeca->id,
        'quantity' => 0.2,
        'unit' => 'kg',
        'expires_on' => null,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'use_pantry',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    $entry = [
        'id' => 10,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $current->id,
        'recipe' => null,
        'drink_recipe_id' => null,
        'drink_recipe' => null,
        'servings' => 2,
        'cook_servings' => 2,
        'leftover_of_entry_id' => null,
        'notes' => null,
    ];

    $this->postJson('/api/shopping/menu/suggest/alternative', [
        'week_start' => '2026-11-09',
        'date' => '2026-11-09',
        'meal' => 'lunch',
        'entries' => [$entry],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.recipe.name', 'Almuerzo con papa seca');
});

it('prefiere una alternativa que reutiliza un producto del mismo dia', function () {
    [$user, $headers] = $this->userWithAuth();
    $quinoa = Product::factory()->create(['name' => 'Quinua compartida alternativa', 'unit' => 'kg']);
    $rice = Product::factory()->create(['name' => 'Arroz alternativo', 'unit' => 'kg']);

    $breakfast = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Desayuno con quinua alternativa',
        'meal_types' => ['breakfast'],
        'base_servings' => 2,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $breakfast->id,
        'product_id' => $quinoa->id,
        'quantity' => 0.1,
        'unit' => 'kg',
        'is_optional' => false,
    ]);

    $current = nutritionRecipe($user->id, 'Almuerzo actual alternativo', 'lunch', [
        'calories_kcal' => 3600,
        'protein_g' => 70,
        'carbs_g' => 780,
        'fat_g' => 8,
    ]);

    $shared = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Almuerzo con quinua alternativa',
        'meal_types' => ['lunch'],
        'base_servings' => 2,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $shared->id,
        'product_id' => $quinoa->id,
        'quantity' => 0.1,
        'unit' => 'kg',
        'is_optional' => false,
    ]);

    $other = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Almuerzo con arroz alternativo',
        'meal_types' => ['lunch'],
        'base_servings' => 2,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $other->id,
        'product_id' => $rice->id,
        'quantity' => 0.1,
        'unit' => 'kg',
        'is_optional' => false,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['breakfast', 'lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    $entries = [
        [
            'id' => 1,
            'plan_date' => '2026-11-09',
            'meal_type' => 'breakfast',
            'kind' => 'recipe',
            'recipe_id' => $breakfast->id,
            'recipe' => ['ingredients' => [['product_id' => $quinoa->id]]],
            'servings' => 2,
        ],
        [
            'id' => 10,
            'plan_date' => '2026-11-09',
            'meal_type' => 'lunch',
            'kind' => 'recipe',
            'recipe_id' => $current->id,
            'recipe' => null,
            'servings' => 2,
        ],
    ];

    $this->postJson('/api/shopping/menu/suggest/alternative', [
        'week_start' => '2026-11-09',
        'date' => '2026-11-09',
        'meal' => 'lunch',
        'entries' => $entries,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.recipe.name', 'Almuerzo con quinua alternativa');
});
