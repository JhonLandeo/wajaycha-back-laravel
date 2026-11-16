<?php

declare(strict_types=1);

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'America/Lima'));
});

afterEach(function () {
    $this->travelBack();
});

function swapEntry(int $userId, Recipe $recipe, string $date, string $status = 'planned'): MealPlanEntry
{
    return MealPlanEntry::factory()->create([
        'user_id' => $userId,
        'plan_date' => $date,
        'meal_type' => 'lunch',
        'kind' => 'recipe',
        'status' => $status,
        'recipe_id' => $recipe->id,
        'servings' => 2,
        'cook_servings' => 2,
    ]);
}

function swapRecipe(int $userId, string $name): Recipe
{
    return Recipe::factory()->create([
        'user_id' => $userId,
        'name' => $name,
        'meal_types' => ['lunch'],
    ]);
}

it('intercambia dos días pendientes aunque ya hayan pasado', function () {
    [$user, $headers] = $this->userWithAuth();
    $a = swapRecipe($user->id, 'Receta del lunes');
    $b = swapRecipe($user->id, 'Receta del martes');
    $monday = swapEntry($user->id, $a, '2026-11-09');
    $tuesday = swapEntry($user->id, $b, '2026-11-10');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $this->postJson('/api/shopping/menu/swap-days', [
        'week_start' => '2026-11-09',
        'date_a' => '2026-11-09',
        'date_b' => '2026-11-10',
    ], $headers)->assertOk()->assertJsonPath('status', 'swapped');

    expect($monday->refresh()->plan_date->toDateString())->toBe('2026-11-10')
        ->and($tuesday->refresh()->plan_date->toDateString())->toBe('2026-11-09');
});

it('rechaza el intercambio si un día tiene una comida ya marcada', function () {
    [$user, $headers] = $this->userWithAuth();
    $a = swapRecipe($user->id, 'Receta cocinada');
    $b = swapRecipe($user->id, 'Receta pendiente');
    swapEntry($user->id, $a, '2026-11-09', 'cooked');
    swapEntry($user->id, $b, '2026-11-10');

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $this->postJson('/api/shopping/menu/swap-days', [
        'week_start' => '2026-11-09',
        'date_a' => '2026-11-09',
        'date_b' => '2026-11-10',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('dates');
});

it('rechaza el intercambio si uno de los días contiene sobras', function () {
    [$user, $headers] = $this->userWithAuth();
    $a = swapRecipe($user->id, 'Receta con sobras');
    $source = swapEntry($user->id, $a, '2026-11-09');
    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'plan_date' => '2026-11-10',
        'meal_type' => 'lunch',
        'kind' => 'leftover',
        'status' => 'planned',
        'recipe_id' => $a->id,
        'servings' => 2,
        'cook_servings' => 0,
        'leftover_of_entry_id' => $source->id,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $this->postJson('/api/shopping/menu/swap-days', [
        'week_start' => '2026-11-09',
        'date_a' => '2026-11-09',
        'date_b' => '2026-11-10',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('dates');
});

it('rechaza el intercambio si una sobra de otro día apunta a una comida del par', function () {
    [$user, $headers] = $this->userWithAuth();
    $a = swapRecipe($user->id, 'Receta con sobra externa');
    $b = swapRecipe($user->id, 'Receta del martes');
    $monday = swapEntry($user->id, $a, '2026-11-09');
    swapEntry($user->id, $b, '2026-11-10');
    MealPlanEntry::factory()->create([
        'user_id' => $user->id,
        'plan_date' => '2026-11-11',
        'meal_type' => 'lunch',
        'kind' => 'leftover',
        'status' => 'planned',
        'recipe_id' => $a->id,
        'servings' => 1,
        'cook_servings' => 0,
        'leftover_of_entry_id' => $monday->id,
    ]);

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $this->postJson('/api/shopping/menu/swap-days', [
        'week_start' => '2026-11-09',
        'date_a' => '2026-11-09',
        'date_b' => '2026-11-10',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('dates');
});

it('rechaza un día que no está activo en la configuración', function () {
    [$user, $headers] = $this->userWithAuth();

    $this->putJson('/api/shopping/menu/settings', [
        'household_size' => 2,
        'meal_types' => ['lunch'],
        'plan_weekdays' => [1, 2],
        'excluded_terms' => [],
    ], $headers)->assertOk();

    $this->postJson('/api/shopping/menu/swap-days', [
        'week_start' => '2026-11-09',
        'date_a' => '2026-11-09',
        'date_b' => '2026-11-11',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('dates');
});
