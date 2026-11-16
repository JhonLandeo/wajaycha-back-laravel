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

/**
 * @return array{0: Recipe, 1: Product}
 */
function previewRecipe(int $userId): array
{
    $product = Product::factory()->create(['name' => 'Arroz '.fake()->unique()->word(), 'unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => 'Arroz con pollo '.fake()->unique()->word(),
        'meal_types' => ['lunch'],
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

it('previews a week without writing and applies the reviewed entries', function () {
    [$user, $headers] = $this->userWithAuth();
    previewRecipe($user->id);
    previewRecipe($user->id);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    config(['services.opencode.api_key' => '']);

    $preview = $this->postJson('/api/shopping/menu/suggest/preview?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonCount(2, 'data.entries')
        ->json('data.entries');

    expect(MealPlanEntry::query()->count())->toBe(0);

    $this->postJson('/api/shopping/menu/suggest/apply', [
        'week_start' => '2026-11-09',
        'entries' => $preview,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('status', 'applied');

    expect(MealPlanEntry::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('rejects a proposal that repeats a main recipe in the same week', function () {
    [$user, $headers] = $this->userWithAuth();
    [$recipe] = previewRecipe($user->id);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $entry = static fn (int $id, string $date): array => [
        'id' => $id,
        'plan_date' => $date,
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => $recipe->id,
        'recipe' => null,
        'drink_recipe_id' => null,
        'drink_recipe' => null,
        'servings' => 2,
        'cook_servings' => 2,
        'leftover_of_entry_id' => null,
        'notes' => null,
    ];

    $this->postJson('/api/shopping/menu/suggest/apply', [
        'week_start' => '2026-11-09',
        'entries' => [$entry(1, '2026-11-09'), $entry(2, '2026-11-10')],
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('entries');

    expect(MealPlanEntry::query()->count())->toBe(0);
});

it('keeps AI recipe drafts out of the database until the preview is applied', function () {
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
    ], $headers)->assertOk();

    $recipesJson = json_encode([
        'recipes' => [[
            'name' => 'Pollo al limón',
            'meal_types' => ['lunch'],
            'course' => 'main',
            'prep_minutes' => 20,
            'equipment' => ['stove'],
            'diet_tags' => ['high_protein'],
            'instructions' => '1. Cocina el pollo. 2. Añade limón.',
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

    $preview = $this->postJson('/api/shopping/menu/suggest/preview?week_start=2026-11-09', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.origin', 'ai')
        ->json('data.entries');

    expect(Recipe::query()->where('origin', 'ai')->count())->toBe(0)
        ->and(MealPlanEntry::query()->count())->toBe(0);

    $this->postJson('/api/shopping/menu/suggest/apply', [
        'week_start' => '2026-11-09',
        'entries' => $preview,
    ], $headers)->assertOk();

    $recipe = Recipe::query()->where('origin', 'ai')->sole();

    expect($recipe->name)->toBe('Pollo al limón')
        ->and(MealPlanEntry::query()->where('user_id', $user->id)->sole()->recipe_id)->toBe($recipe->id);
});

it('keeps an AI draft out of the database when the preview chat adjusts it', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Pollo', 'unit' => 'kg']);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $entry = [
        'id' => 1,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'recipe_id' => 999999,
        'recipe' => [
            'id' => 999999,
            'name' => 'Pollo al limón',
            'base_servings' => 2,
            'meal_types' => ['lunch'],
            'course' => 'main',
            'origin' => 'ai',
            'cuisine' => 'peruvian',
            'required_equipment' => ['stove'],
            'diet_tags' => [],
            'instructions' => '1. Cocina el pollo. 2. Añade limón.',
            'ingredients' => [[
                'product_id' => $product->id,
                'product_name' => 'Pollo',
                'quantity' => 0.5,
                'unit' => 'kg',
                'is_optional' => false,
            ]],
        ],
        'drink_recipe_id' => null,
        'drink_recipe' => null,
        'servings' => 2,
        'cook_servings' => 2,
        'leftover_of_entry_id' => null,
        'notes' => null,
    ];

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => 'La propuesta queda igual.',
                'operations' => [],
            ])]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/menu/suggest/chat', [
        'week_start' => '2026-11-09',
        'message' => '¿qué te parece?',
        'entries' => [$entry],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.entries.0.recipe.origin', 'ai');

    expect(Recipe::query()->where('origin', 'ai')->count())->toBe(0)
        ->and(MealPlanEntry::query()->count())->toBe(0)
        ->and(MenuChatMessage::query()->count())->toBe(0);
});

it('skips the AI recipe generation when the weekly limit is zero', function () {
    [$user, $headers] = $this->userWithAuth();
    previewRecipe($user->id);

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

    $this->postJson('/api/shopping/menu/suggest/preview?week_start=2026-11-09', [], $headers)
        ->assertOk();

    Http::assertSentCount(1);
});
