<?php

declare(strict_types=1);

use App\Models\MealPlanEntry;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: Recipe, 1: Product}
 */
function suggestionRecipe(int $userId, string $name = 'Arroz con pollo', string $meal = 'lunch'): array
{
    $product = Product::factory()->create(['name' => 'Arroz '.fake()->unique()->word(), 'unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => $name,
        'meal_types' => [$meal],
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
