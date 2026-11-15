<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('rejects unauthenticated recipe drafts', function () {
    $this->postJson('/api/shopping/recipes/draft', ['idea' => 'algo con pollo'])
        ->assertStatus(401);
});

it('returns an editable AI draft with ingredients and preparation without saving it', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['name' => 'Pollo', 'unit' => 'kg']);

    $recipe = [
        'name' => 'Pollo con arroz',
        'meal_types' => ['lunch'],
        'course' => 'main',
        'prep_minutes' => 25,
        'equipment' => ['stove'],
        'diet_tags' => [],
        'instructions' => '1. Cocina el pollo. 2. Añade el arroz.',
        'ingredients' => [
            ['producto_id' => $product->id, 'quantity' => 0.5, 'unit' => 'kg'],
        ],
    ];

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['recipe' => $recipe])]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/recipes/draft', ['idea' => 'algo con pollo'], $headers)
        ->assertOk()
        ->assertJsonPath('data.name', 'Pollo con arroz')
        ->assertJsonPath('data.instructions', '1. Cocina el pollo. 2. Añade el arroz.')
        ->assertJsonPath('data.ingredients.0.product_id', $product->id)
        ->assertJsonPath('data.ingredients.0.product_name', 'Pollo')
        ->assertJsonPath('data.ingredients.0.quantity', 0.5);

    expect(Recipe::query()->count())->toBe(0);
});

it('returns a validation error when the AI cannot produce a valid recipe', function () {
    [$user, $headers] = $this->userWithAuth();
    Product::factory()->create(['unit' => 'kg']);

    config([
        'services.opencode.api_key' => 'test-key',
        'services.opencode.base_url' => 'https://opencode.test/go',
        'services.opencode.model' => 'test-model',
    ]);
    Http::fake([
        'opencode.test/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['recipe' => ['name' => '']])]]]]],
        ]),
    ]);

    $this->postJson('/api/shopping/recipes/draft', ['idea' => 'algo'], $headers)
        ->assertStatus(422)
        ->assertJsonPath('message', 'No pudimos generar una receta. Intenta con otra idea.');
});
