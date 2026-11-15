<?php

declare(strict_types=1);

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
function suggestionRecipe(int $userId, string $name = 'Arroz con pollo', string $meal = 'lunch', string $course = 'main', array $equipment = []): array
{
    $product = Product::factory()->create(['name' => 'Arroz '.fake()->unique()->word(), 'unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => $name,
        'meal_types' => [$meal],
        'course' => $course,
        'required_equipment' => $equipment,
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
    suggestionRecipe($user->id);

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

it('caps a recipe at two occurrences when another candidate is available', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipeA] = suggestionRecipe($user->id, 'Receta A');
    [$recipeB] = suggestionRecipe($user->id, 'Receta B');

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

    expect($entries->where('recipe_id', $recipeA->id))->toHaveCount(2)
        ->and($entries->where('recipe_id', $recipeB->id))->toHaveCount(2);
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
