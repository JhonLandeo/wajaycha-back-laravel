<?php

declare(strict_types=1);

use App\Models\MealPlanEntry;
use App\Models\MenuChatMessage;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function chatRecipe(int $userId, string $name = 'Arroz con pollo'): Recipe
{
    $product = Product::factory()->create(['unit' => 'kg']);
    $recipe = Recipe::factory()->create([
        'user_id' => $userId,
        'name' => $name,
        'meal_types' => ['lunch'],
        'base_servings' => 2,
        'prep_minutes' => 30,
    ]);
    RecipeIngredient::factory()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $product->id,
        'quantity' => 0.5,
        'unit' => 'kg',
        'is_optional' => false,
    ]);

    return $recipe;
}

function fakeChatReply(array $payload): void
{
    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);

    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]],
        ]),
    ]);
}

it('rejects unauthenticated chat access', function () {
    $this->postJson('/api/shopping/menu/chat', [
        'week_start' => '2026-11-09',
        'message' => 'cambia el lunes',
    ])->assertStatus(401);
});

it('returns validated operations and previews them without touching the menu', function () {
    [$user, $headers] = $this->userWithAuth();
    $recipe = chatRecipe($user->id);

    fakeChatReply([
        'reply' => 'Cambio el lunes por arroz con pollo.',
        'operations' => [[
            'op' => 'swap_recipe',
            'date' => '2026-11-09',
            'meal' => 'lunch',
            'recipe_id' => $recipe->id,
        ]],
    ]);

    $this->postJson('/api/shopping/menu/chat', [
        'week_start' => '2026-11-09',
        'message' => 'el lunes quiero arroz con pollo',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.source', 'ai')
        ->assertJsonPath('data.reply', 'Cambio el lunes por arroz con pollo.')
        ->assertJsonPath('data.operations.0.op', 'swap_recipe')
        ->assertJsonPath('data.operations.0.recipe_id', $recipe->id)
        ->assertJsonCount(1, 'data.preview');

    expect(MealPlanEntry::query()->count())->toBe(0)
        ->and(MenuChatMessage::query()->count())->toBe(2)
        ->and(MenuChatMessage::query()->where('role', 'assistant')->first()->payload['operations'])->toHaveCount(1);
});

it('drops operations for inactive dates, wrong meals and unknown recipes', function () {
    [$user, $headers] = $this->userWithAuth();
    $recipe = chatRecipe($user->id);

    fakeChatReply([
        'reply' => 'Propongo varios cambios.',
        'operations' => [
            ['op' => 'swap_recipe', 'date' => '2026-12-01', 'meal' => 'lunch', 'recipe_id' => $recipe->id],
            ['op' => 'swap_recipe', 'date' => '2026-11-09', 'meal' => 'dinner', 'recipe_id' => $recipe->id],
            ['op' => 'swap_recipe', 'date' => '2026-11-09', 'meal' => 'lunch', 'recipe_id' => 99999],
            ['op' => 'set_cook_servings', 'date' => '2026-11-09', 'meal' => 'lunch', 'cook_servings' => 99],
        ],
    ]);

    $this->postJson('/api/shopping/menu/chat', [
        'week_start' => '2026-11-09',
        'message' => 'cambia todo',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.operations', [])
        ->assertJsonPath('data.preview', []);
});

it('applies only the confirmed operations through the normal entry writer', function () {
    [$user, $headers] = $this->userWithAuth();
    $recipe = chatRecipe($user->id);

    $this->postJson('/api/shopping/menu/chat/apply', [
        'week_start' => '2026-11-09',
        'operations' => [[
            'op' => 'swap_recipe',
            'date' => '2026-11-09',
            'meal' => 'lunch',
            'recipe_id' => $recipe->id,
        ]],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.applied.0.op', 'swap_recipe');

    $entry = MealPlanEntry::query()->where('user_id', $user->id)->whereDate('plan_date', '2026-11-09')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->recipe_id)->toBe($recipe->id)
        ->and($entry->meal_type->value)->toBe('lunch');
});

it('applies a day clear and a meal marked as eaten out', function () {
    [$user, $headers] = $this->userWithAuth();
    $recipe = chatRecipe($user->id);

    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'recipe_id' => $recipe->id,
        'plan_date' => '2026-11-09',
        'meal_type' => 'lunch',
    ]);

    $this->postJson('/api/shopping/menu/chat/apply', [
        'week_start' => '2026-11-09',
        'operations' => [
            ['op' => 'mark_eat_out', 'date' => '2026-11-10', 'meal' => 'dinner'],
            ['op' => 'clear_day', 'date' => '2026-11-09'],
        ],
    ], $headers)->assertOk();

    expect(MealPlanEntry::query()->whereDate('plan_date', '2026-11-09')->count())->toBe(0)
        ->and(MealPlanEntry::query()->whereDate('plan_date', '2026-11-10')->first()->kind->value)->toBe('eat_out');
});

it('answers with a fallback reply when the AI is unavailable', function () {
    [$user, $headers] = $this->userWithAuth();
    chatRecipe($user->id);
    config(['services.opencode.api_key' => '']);

    $this->postJson('/api/shopping/menu/chat', [
        'week_start' => '2026-11-09',
        'message' => 'cambia el lunes',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.source', 'fallback')
        ->assertJsonPath('data.operations', []);
});
