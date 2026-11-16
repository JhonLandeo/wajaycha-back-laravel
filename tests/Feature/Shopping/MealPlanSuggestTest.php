<?php

declare(strict_types=1);

use App\Models\ConsumptionHabit;
use App\Models\MealPlanEntry;
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

/**
 * @return array{0: Recipe, 1: Product}
 */
function suggestionRecipe(int $userId, string $name = 'Arroz con pollo', string $meal = 'lunch', string $course = 'main', array $equipment = [], array $dietTags = [], string $cuisine = 'peruvian'): array
{
    $product = Product::factory()->create(['name' => 'Arroz '.fake()->unique()->word(), 'unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => $name,
        'meal_types' => [$meal],
        'course' => $course,
        'required_equipment' => $equipment,
        'diet_tags' => $dietTags,
        'cuisine' => $cuisine,
        'base_servings' => 2,
        'prep_minutes' => 30,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $product->id,
        'quantity' => 0.5,
        'unit' => 'kg',
    ]);

    return [$recipe, $product];
}

it('uses the AI choice and turns its extra portions into leftovers', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe] = suggestionRecipe($user->id);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $aiJson = json_encode([
        'days' => [[
            'date' => '2026-11-09',
            'meals' => ['lunch' => ['recipe_id' => $recipe->id, 'cook_servings' => 4]],
        ]],
    ]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $aiJson]]]]],
        ]),
    ]);

    $response = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'ai')
        ->assertJsonCount(2, 'data.entries');

    $entries = MealPlanEntry::where('user_id', $user->id)->orderBy('plan_date')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->kind->value)->toBe('recipe')
        ->and($entries[0]->cook_servings)->toBe(4)
        ->and($entries[1]->kind->value)->toBe('leftover')
        ->and($entries[1]->leftover_of_entry_id)->toBe($entries[0]->id)
        ->and($entries[1]->servings)->toBe(2);
});

it('falls back to a deterministic week when the AI is unavailable', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Receta A');
    suggestionRecipe($user->id, 'Receta B');
    suggestionRecipe($user->id, 'Receta C');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonCount(3, 'data.entries');

    expect(MealPlanEntry::where('user_id', $user->id)->where('kind', 'recipe')->count())->toBe(3);
});

it('prefers the recipe the pantry already covers when the strategy says so', function () {
    [$user, $headers] = $this->userWithAuth();
    [, $coveredProduct] = suggestionRecipe($user->id, 'Arroz cubierto');
    suggestionRecipe($user->id, 'Lentejas sin despensa');

    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $coveredProduct->id,
        'quantity' => 10,
        'unit' => 'kg',
        'expires_on' => '2026-11-20',
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'use_pantry',
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonPath('data.entries.0.recipe.name', 'Arroz cubierto');
});

it('does not propose recipes excluded by the user', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Arroz con pollo');
    suggestionRecipe($user->id, 'Lentejas guisadas');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => ['pollo'],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Lentejas guisadas');
});

it('spreads the pantry across recipes instead of repeating the one that is covered', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipeA, $productA] = suggestionRecipe($user->id, 'Receta A');
    [$recipeB, $productB] = suggestionRecipe($user->id, 'Receta B');
    [$recipeC, $productC] = suggestionRecipe($user->id, 'Receta C');

    // A se cubre por completo y vence pronto: antes ganaba siempre porque la
    // despensa no se agotaba entre elecciones.
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $productA->id,
        'quantity' => 0.5,
        'unit' => 'kg',
        'expires_on' => now()->addDays(2)->toDateString(),
    ]);
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $productB->id,
        'quantity' => 0.25,
        'unit' => 'kg',
        'expires_on' => null,
    ]);
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $productC->id,
        'quantity' => 0.25,
        'unit' => 'kg',
        'expires_on' => null,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3],
        'excluded_terms' => [],
        'menu_strategy' => 'use_pantry',
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback');

    $names = MealPlanEntry::query()
        ->where('user_id', $user->id)
        ->where('kind', 'recipe')
        ->orderBy('plan_date')
        ->with('recipe')
        ->get()
        ->map(fn (MealPlanEntry $entry): string => (string) $entry->recipe?->name)
        ->all();

    expect($names)->toBe(['Receta A', 'Receta B', 'Receta C']);
});

it('ignores expired pantry when the strategy is use_pantry', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipeA, $productA] = suggestionRecipe($user->id, 'Receta A');
    [$recipeB, $productB] = suggestionRecipe($user->id, 'Receta B');

    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $productA->id,
        'quantity' => 0.5,
        'unit' => 'kg',
        'expires_on' => now()->subDay()->toDateString(),
    ]);
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $productB->id,
        'quantity' => 0.5,
        'unit' => 'kg',
        'expires_on' => null,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'use_pantry',
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Receta B');
});

it('rejects an AI suggestion that repeats the same recipe on consecutive days', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipeA] = suggestionRecipe($user->id, 'Receta A');
    [$recipeB] = suggestionRecipe($user->id, 'Receta B');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $aiJson = json_encode([
        'days' => [
            ['date' => '2026-11-09', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
            ['date' => '2026-11-10', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
        ],
    ]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $aiJson]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'ai');

    $names = MealPlanEntry::query()
        ->where('user_id', $user->id)
        ->where('kind', 'recipe')
        ->orderBy('plan_date')
        ->with('recipe')
        ->get()
        ->map(fn (MealPlanEntry $entry): string => (string) $entry->recipe?->name)
        ->all();

    expect($names)->toBe(['Receta A', 'Receta B']);
});

it('uses every recipe at most once when the AI keeps insisting on the same one', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipeA] = suggestionRecipe($user->id, 'Receta A');
    [$recipeB] = suggestionRecipe($user->id, 'Receta B');
    [$recipeC] = suggestionRecipe($user->id, 'Receta C');
    [$recipeD] = suggestionRecipe($user->id, 'Receta D');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3, 4],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $aiJson = json_encode([
        'days' => [
            ['date' => '2026-11-09', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
            ['date' => '2026-11-10', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
            ['date' => '2026-11-11', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
            ['date' => '2026-11-12', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
        ],
    ]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $aiJson]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'ai');

    $entries = MealPlanEntry::query()
        ->where('user_id', $user->id)
        ->where('kind', 'recipe')
        ->with('recipe')
        ->get();

    foreach ([$recipeA, $recipeB, $recipeC, $recipeD] as $recipe) {
        expect($entries->where('recipe_id', $recipe->id))->toHaveCount(1);
    }
});

it('does not propose days that already passed in the current week', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Receta A');

    // Miércoles: lunes y martes ya pasaron.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'America/Lima'));

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk();

    $dates = MealPlanEntry::query()
        ->where('user_id', $user->id)
        ->where('kind', 'recipe')
        ->orderBy('plan_date')
        ->get()
        ->map(fn (MealPlanEntry $entry): string => $entry->plan_date->toDateString())
        ->all();

    expect($dates)->toBe(['2026-11-11']);
});

it('leaves a finished week untouched when there is nothing left to plan', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe] = suggestionRecipe($user->id, 'Receta A');

    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'America/Lima'));

    $existing = MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback');

    expect(MealPlanEntry::query()->whereKey($existing->id)->exists())->toBeTrue();
});

it('pairs a drink with a breakfast main when the fallback builds the week', function () {
    [$user, $headers] = $this->userWithAuth();
    [$main] = suggestionRecipe($user->id, 'Pan con palta', 'breakfast', 'main');
    [$drink] = suggestionRecipe($user->id, 'Bebida de soya', 'breakfast', 'drink');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['breakfast'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Pan con palta')
        ->assertJsonPath('data.entries.0.drink_recipe.name', 'Bebida de soya');

    $entry = MealPlanEntry::query()->where('user_id', $user->id)->sole();

    expect($entry->recipe_id)->toBe($main->id)
        ->and($entry->drink_recipe_id)->toBe($drink->id);
});

it('does not propose recipes that need equipment the user does not have', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Al horno', 'lunch', 'main', ['oven']);
    suggestionRecipe($user->id, 'A la cocina', 'lunch', 'main', ['stove']);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'available_equipment' => ['stove'],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'A la cocina');
});

it('saves an AI recipe composed from catalogue products and uses it in the week', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Pollo', 'unit' => 'kg']);

    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1.0,
        'unit' => 'kg',
        'expires_on' => null,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'available_equipment' => ['stove'],
    ], $headers)->assertOk();

    $recipesJson = json_encode([
        'recipes' => [[
            'name' => 'Pollo con arroz',
            'meal_types' => ['lunch'],
            'course' => 'main',
            'prep_minutes' => 20,
            'equipment' => ['stove'],
            'ingredients' => [
                ['producto_id' => $product->id, 'quantity' => 0.5, 'unit' => 'kg'],
            ],
        ]],
    ]);
    $daysJson = json_encode(['days' => []]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake(function ($request) use ($recipesJson, $daysJson) {
        $text = str_contains($request->body(), 'cocinero peruano') ? $recipesJson : $daysJson;

        return Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
        ]);
    });

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Pollo con arroz');

    $recipe = Recipe::query()->where('origin', 'ai')->sole();

    expect($recipe->name)->toBe('Pollo con arroz')
        ->and($recipe->course->value)->toBe('main')
        ->and($recipe->required_equipment)->toBe(['stove'])
        ->and($recipe->ingredients)->toHaveCount(1);
});

it('uses every recipe before repeating even if the AI insists on the same one', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipeA] = suggestionRecipe($user->id, 'Receta A');
    [$recipeB] = suggestionRecipe($user->id, 'Receta B');
    $recipeC = suggestionRecipe($user->id, 'Receta C')[0];

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $aiJson = json_encode([
        'days' => [
            ['date' => '2026-11-09', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
            ['date' => '2026-11-10', 'meals' => ['lunch' => ['recipe_id' => $recipeB->id, 'cook_servings' => 2]]],
            ['date' => '2026-11-11', 'meals' => ['lunch' => ['recipe_id' => $recipeA->id, 'cook_servings' => 2]]],
        ],
    ]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake(function ($request) use ($aiJson) {
        $text = str_contains($request->body(), 'cocinero peruano') ? json_encode(['recipes' => []]) : $aiJson;

        return Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
        ]);
    });

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'ai');

    $names = MealPlanEntry::query()
        ->where('user_id', $user->id)
        ->where('kind', 'recipe')
        ->orderBy('plan_date')
        ->with('recipe')
        ->get()
        ->map(fn (MealPlanEntry $entry): string => (string) $entry->recipe?->name)
        ->all();

    expect($names)->toBe(['Receta A', 'Receta B', 'Receta C'])
        ->and($names)->toContain($recipeC->name);
});

it('filters recipes that do not match a vegetarian profile', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Con pollo', 'lunch', 'main', [], []);
    suggestionRecipe($user->id, 'Vegetariana', 'lunch', 'main', [], ['vegetarian']);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'diet_profile' => 'vegetarian',
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Vegetariana');
});

it('prefers high-protein recipes when the profile asks for it', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Normal', 'lunch', 'main', [], []);
    suggestionRecipe($user->id, 'Proteica', 'lunch', 'main', [], ['high_protein']);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'diet_profile' => 'high_protein',
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Proteica');
});

it('does not ask the AI for new recipes when the user chooses zero', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Del recetario');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['days' => []])]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk();

    Http::assertSentCount(1);
    expect(Recipe::where('origin', 'ai')->count())->toBe(0);
});

it('prefers recipes that use a declared habit product', function () {
    [$user, $headers] = $this->userWithAuth();
    [$habitRecipe, $habitProduct] = suggestionRecipe($user->id, 'Con habito');
    suggestionRecipe($user->id, 'Sin habito');

    ConsumptionHabit::factory()->create([
        'user_id' => $user->id,
        'product_id' => $habitProduct->id,
        'weekly_quantity' => 1,
        'unit' => 'kg',
        'is_active' => true,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Con habito');
});

it('rejects an AI menu that ignores a high-protein profile when tagged recipes exist', function () {
    [$user, $headers] = $this->userWithAuth();
    [$normalRecipe] = suggestionRecipe($user->id, 'Normal');
    suggestionRecipe($user->id, 'Proteica', 'lunch', 'main', [], ['high_protein']);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'diet_profile' => 'high_protein',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    $aiJson = json_encode([
        'days' => [
            ['date' => '2026-11-09', 'meals' => ['lunch' => ['recipe_id' => $normalRecipe->id, 'cook_servings' => 2]]],
        ],
    ]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $aiJson]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonPath('data.entries.0.recipe.name', 'Proteica');
});

it('prefers Peruvian recipes over international ones', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Internacional', 'lunch', 'main', [], [], 'international');
    suggestionRecipe($user->id, 'Peruana', 'lunch', 'main', [], [], 'peruvian');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Peruana');
});

it('always allows a no-cook recipe even when the user only declares a stove', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Ensalada de fruta', 'breakfast', 'main', ['no_cook'], ['vegetarian'], 'international');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['breakfast'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'available_equipment' => ['stove'],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Ensalada de fruta');
});

it('does not collapse a light profile to the only light recipe', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Ligera', 'lunch', 'main', [], ['light']);
    suggestionRecipe($user->id, 'Normal A');
    suggestionRecipe($user->id, 'Normal B');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2, 3],
        'excluded_terms' => [],
        'diet_profile' => 'light',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $entries = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonCount(3, 'data.entries')
        ->json('data.entries');

    $names = array_column($entries, 'recipe');

    expect($names[0]['name'])->toBe('Ligera')
        ->and(array_filter($names, static fn (array $recipe): bool => $recipe['name'] === 'Ligera'))->toHaveCount(1);
});

it('accepts an AI choice without the light tag when a light recipe exists', function () {
    [$user, $headers] = $this->userWithAuth();
    suggestionRecipe($user->id, 'Ligera', 'lunch', 'main', [], ['light']);
    [$normal] = suggestionRecipe($user->id, 'Normal');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'diet_profile' => 'light',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    $aiJson = json_encode([
        'days' => [
            ['date' => '2026-11-09', 'meals' => ['lunch' => ['recipe_id' => $normal->id, 'cook_servings' => 2]]],
        ],
    ]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $aiJson]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'ai')
        ->assertJsonPath('data.entries.0.recipe.name', 'Normal');
});

it('uses the priority pantry product even when the recipe has missing ingredients', function () {
    [$user, $headers] = $this->userWithAuth();
    [$carapulcra, $papaSeca] = suggestionRecipe($user->id, 'Carapulcra de prueba', 'lunch');
    $carapulcra->update(['prep_minutes' => 50]);

    $carne = Product::factory()->create(['name' => 'Carne de cerdo '.fake()->unique()->word(), 'unit' => 'kg']);
    RecipeIngredient::factory()->create([
        'recipe_id' => $carapulcra->id,
        'product_id' => $carne->id,
        'quantity' => 0.4,
        'unit' => 'kg',
        'is_optional' => false,
    ]);
    suggestionRecipe($user->id, 'Receta sin despensa');

    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $papaSeca->id,
        'quantity' => 0.5,
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

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonPath('data.entries.0.recipe.name', 'Carapulcra de prueba');
});

it('prioriza el producto de despensa que vence primero', function () {
    [$user, $headers] = $this->userWithAuth();
    [$first] = suggestionRecipe($user->id, 'Receta que vence primero', 'lunch');
    [$second, $secondProduct] = suggestionRecipe($user->id, 'Receta que vence despues', 'lunch');

    $firstProduct = $first->ingredients()->with('product')->first()?->product;
    expect($firstProduct)->not->toBeNull();

    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $firstProduct->id,
        'quantity' => 0.5,
        'unit' => 'kg',
        'expires_on' => '2026-11-11',
    ]);
    PantryItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $secondProduct->id,
        'quantity' => 0.5,
        'unit' => 'kg',
        'expires_on' => '2026-11-20',
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'use_pantry',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Receta que vence primero');
});

it('reutiliza un producto base entre comidas del mismo dia', function () {
    [$user, $headers] = $this->userWithAuth();
    $quinoa = Product::factory()->create(['name' => 'Quinua compartida', 'unit' => 'kg']);
    $rice = Product::factory()->create(['name' => 'Arroz distinto', 'unit' => 'kg']);

    $breakfast = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Desayuno con quinua',
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

    $lunchShared = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Almuerzo con quinua',
        'meal_types' => ['lunch'],
        'base_servings' => 2,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $lunchShared->id,
        'product_id' => $quinoa->id,
        'quantity' => 0.1,
        'unit' => 'kg',
        'is_optional' => false,
    ]);

    $lunchOther = Recipe::factory()->create([
        'user_id' => $user->id,
        'name' => 'Almuerzo con arroz',
        'meal_types' => ['lunch'],
        'base_servings' => 2,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $lunchOther->id,
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

    config(['services.opencode.api_key' => '']);

    $entries = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->json('data.entries');

    $lunch = collect($entries)->firstWhere('meal_type', 'lunch');

    expect($lunch['recipe']['name'])->toBe('Almuerzo con quinua');
});

it('excluye una cena que supera el tiempo específico de la cena', function () {
    [$user, $headers] = $this->userWithAuth();
    [$slow] = suggestionRecipe($user->id, 'Cena lenta', 'dinner');
    [$fast] = suggestionRecipe($user->id, 'Cena rápida', 'dinner');
    $slow->update(['prep_minutes' => 26]);
    $fast->update(['prep_minutes' => 15]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'max_prep_minutes' => 30,
        'dinner_max_prep_minutes' => 20,
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Cena rápida');
});

it('prefiere una cena light por debajo del tope de calorías', function () {
    [$user, $headers] = $this->userWithAuth();
    [$light, $lightProduct] = suggestionRecipe($user->id, 'Cena ligera', 'dinner', 'main', [], ['light']);
    [$heavy, $heavyProduct] = suggestionRecipe($user->id, 'Cena pesada', 'dinner');
    $light->update(['prep_minutes' => 20]);
    $heavy->update(['prep_minutes' => 20]);

    $lightProduct->update([
        'calories_kcal' => 1200,
        'protein_g' => 80,
        'carbs_g' => 40,
        'fat_g' => 30,
    ]);
    $heavyProduct->update([
        'calories_kcal' => 3600,
        'protein_g' => 80,
        'carbs_g' => 300,
        'fat_g' => 100,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'max_prep_minutes' => 30,
        'dinner_max_prep_minutes' => 20,
        'dinner_calorie_limit' => 600,
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Cena ligera');
});

it('en modo ahorro la cena es una sobra del almuerzo', function () {
    [$user, $headers] = $this->userWithAuth();
    [$lunch] = suggestionRecipe($user->id, 'Almuerzo que rinde cena', 'lunch', 'main', [], ['light']);
    $lunch->update(['meal_types' => ['lunch', 'dinner']]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch', 'dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'save_money',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $entries = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->json('data.entries');

    $lunchEntry = collect($entries)->firstWhere('meal_type', 'lunch');
    $dinnerEntry = collect($entries)->firstWhere('meal_type', 'dinner');

    expect($lunchEntry['cook_servings'])->toBe(4)
        ->and($dinnerEntry['kind'])->toBe('leftover')
        ->and($dinnerEntry['leftover_of_entry_id'])->toBe($lunchEntry['id'])
        ->and($dinnerEntry['recipe_id'])->toBe($lunchEntry['recipe_id'])
        ->and($dinnerEntry['servings'])->toBe(2)
        ->and($dinnerEntry['cook_servings'])->toBe(0);
});

it('en modo ahorro reemplaza la cena elegida por sobras del almuerzo', function () {
    [$user, $headers] = $this->userWithAuth();
    [$lunch] = suggestionRecipe($user->id, 'Almuerzo que reemplaza cena', 'lunch', 'main', [], ['light']);
    $lunch->update(['meal_types' => ['lunch', 'dinner']]);
    [$dinner] = suggestionRecipe($user->id, 'Cena que sobra', 'dinner');
    $dinner->update(['prep_minutes' => 15]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch', 'dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'save_money',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $entries = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->json('data.entries');

    $lunchEntry = collect($entries)->firstWhere('meal_type', 'lunch');
    $dinnerEntry = collect($entries)->firstWhere('meal_type', 'dinner');

    expect($dinnerEntry['kind'])->toBe('leftover')
        ->and($dinnerEntry['recipe_id'])->toBe($lunchEntry['recipe_id'])
        ->and($dinnerEntry['recipe_id'])->not->toBe($dinner->id);
});

it('no usa sobras de un almuerzo que no es light', function () {
    [$user, $headers] = $this->userWithAuth();
    [$lunch] = suggestionRecipe($user->id, 'Almuerzo no light', 'lunch');
    $lunch->update(['meal_types' => ['lunch', 'dinner']]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch', 'dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'save_money',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $entries = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->json('data.entries');

    expect(collect($entries)->firstWhere('meal_type', 'dinner'))->toBeNull();
});

it('no usa sobras de un almuerzo con pescado fresco', function () {
    [$user, $headers] = $this->userWithAuth();
    [$lunch, $fish] = suggestionRecipe($user->id, 'Almuerzo con pescado', 'lunch', 'main', [], ['light']);
    $fish->update(['slug' => 'pescado-fresco']);
    $lunch->update(['meal_types' => ['lunch', 'dinner']]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch', 'dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'menu_strategy' => 'save_money',
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $entries = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->json('data.entries');

    expect(collect($entries)->firstWhere('meal_type', 'dinner'))->toBeNull();
});

it('permite hasta cinco minutos extra en la cena cuando no hay otra opción', function () {
    [$user, $headers] = $this->userWithAuth();
    [$dinner] = suggestionRecipe($user->id, 'Cena de veinte minutos', 'dinner');
    $dinner->update(['prep_minutes' => 20]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['dinner'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
        'dinner_max_prep_minutes' => 15,
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.name', 'Cena de veinte minutos');
});

it('enfoca la generación en la cena cuando faltan cenas para la semana', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Pollo para foco', 'unit' => 'kg']);
    [$dinner] = suggestionRecipe($user->id, 'Cena existente', 'dinner');
    $dinner->update(['prep_minutes' => 20]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['dinner'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
        'available_equipment' => ['stove'],
        'dinner_max_prep_minutes' => 15,
        'new_recipes_per_week' => 0,
    ], $headers)->assertOk();

    $recipesJson = json_encode([
        'recipes' => [[
            'name' => 'Cena nueva de quince minutos',
            'meal_types' => ['dinner'],
            'course' => 'main',
            'prep_minutes' => 15,
            'equipment' => ['stove'],
            'instructions' => '1. Cocina rápido.',
            'ingredients' => [
                ['producto_id' => $product->id, 'quantity' => 0.3, 'unit' => 'kg'],
            ],
        ]],
    ]);
    $daysJson = json_encode(['days' => []]);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake(function ($request) use ($recipesJson, $daysJson) {
        $text = str_contains($request->body(), 'cocinero peruano') ? $recipesJson : $daysJson;

        return Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
        ]);
    });

    $entries = $this->postJson('/api/shopping/menu/suggest?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data.entries')
        ->json('data.entries');

    expect(Recipe::query()->where('origin', 'ai')->where('name', 'Cena nueva de quince minutos')->exists())->toBeTrue()
        ->and(collect($entries)->every(fn (array $entry): bool => $entry['meal_type'] === 'dinner'))->toBeTrue();
});
