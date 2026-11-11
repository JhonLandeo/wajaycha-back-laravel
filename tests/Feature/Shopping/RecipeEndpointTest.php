<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function recipePayload(Product $product, array $overrides = []): array
{
    return [
        'name' => 'Arroz con pollo',
        'base_servings' => 2,
        'meal_types' => ['lunch', 'dinner'],
        'prep_minutes' => 40,
        'instructions' => 'Cocinar el pollo y mezclar con el arroz.',
        'ingredients' => [
            [
                'product_id' => $product->id,
                'quantity' => 200,
                'unit' => 'g',
                'is_optional' => false,
            ],
        ],
        ...$overrides,
    ];
}

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/shopping/recipes')->assertStatus(401);
});

it('lists the global catalogue plus the user recipes, never another user recipe', function () {
    [$user, $headers] = $this->userWithAuth();
    [$other] = $this->userWithAuth();

    Recipe::factory()->global()->create(['name' => 'Receta global']);
    Recipe::factory()->create(['user_id' => $user->id, 'name' => 'Receta propia']);
    Recipe::factory()->create(['user_id' => $other->id, 'name' => 'Receta ajena']);

    $response = $this->getJson('/api/shopping/recipes', $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Receta global', 'Receta propia')
        ->not->toContain('Receta ajena');
});

it('creates a private recipe with a compatible ingredient unit', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/recipes', recipePayload($product), $headers)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Arroz con pollo')
        ->assertJsonPath('data.is_global', false)
        ->assertJsonPath('data.meal_types.0', 'lunch')
        ->assertJsonPath('data.ingredients.0.product_name', $product->name)
        ->assertJsonPath('data.ingredients.0.quantity', 200)
        ->assertJsonPath('data.ingredients.0.unit', 'g');

    $recipe = Recipe::where('user_id', $user->id)->sole();
    expect($recipe->ingredients()->count())->toBe(1);
});

it('rejects an ingredient unit that cannot convert to the product unit', function () {
    [, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/recipes', recipePayload($product, [
        'ingredients' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit' => 'l',
        ]],
    ]), $headers)->assertStatus(422)->assertJsonValidationErrors('ingredients.0.unit');
});

it('rejects a product from another user or a duplicate recipe name', function () {
    [$user, $headers] = $this->userWithAuth();
    [$other] = $this->userWithAuth();
    $foreignProduct = Product::factory()->ownedBy($other->id)->create(['unit' => 'kg']);
    $ownProduct = Product::factory()->create(['unit' => 'kg']);

    $this->postJson('/api/shopping/recipes', recipePayload($foreignProduct), $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('ingredients.0.product_id');

    Recipe::factory()->create(['user_id' => $user->id, 'name' => 'Arroz con pollo']);

    $this->postJson('/api/shopping/recipes', recipePayload($ownProduct), $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('updates the user recipe and replaces its ingredients', function () {
    [$user, $headers] = $this->userWithAuth();
    $firstProduct = Product::factory()->create(['unit' => 'kg']);
    $secondProduct = Product::factory()->create(['unit' => 'unidad']);
    $recipe = Recipe::factory()->create(['user_id' => $user->id, 'name' => 'Receta vieja']);
    RecipeIngredient::factory()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $firstProduct->id,
        'unit' => 'kg',
        'quantity' => 1,
    ]);

    $this->putJson("/api/shopping/recipes/{$recipe->id}", [
        'name' => 'Receta nueva',
        'base_servings' => 4,
        'meal_types' => ['dinner'],
        'prep_minutes' => 20,
        'ingredients' => [[
            'product_id' => $secondProduct->id,
            'quantity' => 2,
            'unit' => 'unidad',
        ]],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.name', 'Receta nueva')
        ->assertJsonPath('data.base_servings', 4)
        ->assertJsonPath('data.ingredients.0.product_id', $secondProduct->id);

    expect($recipe->fresh()->ingredients()->count())->toBe(1);
});

it('never edits or deletes a global recipe or another user recipe', function () {
    [, $headers] = $this->userWithAuth();
    $global = Recipe::factory()->global()->create();
    [$other] = $this->userWithAuth();
    $foreign = Recipe::factory()->create(['user_id' => $other->id]);
    $product = Product::factory()->create(['unit' => 'kg']);

    $payload = recipePayload($product, ['name' => 'Intento ajeno']);

    $this->putJson("/api/shopping/recipes/{$global->id}", $payload, $headers)->assertStatus(404);
    $this->deleteJson("/api/shopping/recipes/{$global->id}", [], $headers)->assertStatus(404);
    $this->putJson("/api/shopping/recipes/{$foreign->id}", $payload, $headers)->assertStatus(404);
    $this->deleteJson("/api/shopping/recipes/{$foreign->id}", [], $headers)->assertStatus(404);
});

it('deletes a user recipe and its ingredients', function () {
    [$user, $headers] = $this->userWithAuth();
    $product = Product::factory()->create(['unit' => 'kg']);
    $recipe = Recipe::factory()->create(['user_id' => $user->id]);
    RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'product_id' => $product->id, 'unit' => 'kg']);

    $this->deleteJson("/api/shopping/recipes/{$recipe->id}", [], $headers)
        ->assertOk()
        ->assertJsonPath('status', 'deleted');

    expect(Recipe::find($recipe->id))->toBeNull()
        ->and(RecipeIngredient::where('recipe_id', $recipe->id)->exists())->toBeFalse();
});
