<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\FoodBudget;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'America/Lima'));
});

/**
 * @return array{0: Category, 1: Category}
 */
function foodCategories(int $userId): array
{
    $parent = Category::factory()->create([
        'user_id' => $userId,
        'name' => 'Alimentos',
        'parent_id' => null,
        'type' => 'expense',
        'monthly_budget' => 500,
    ]);

    $child = Category::factory()->create([
        'user_id' => $userId,
        'name' => 'Mercado',
        'parent_id' => $parent->id,
        'type' => 'expense',
        'monthly_budget' => 0,
    ]);

    return [$parent, $child];
}

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/shopping/food-budget')->assertStatus(401);
});

it('reports an unconfigured food budget before the first save', function () {
    [, $headers] = $this->userWithAuth();

    $this->getJson('/api/shopping/food-budget', $headers)
        ->assertOk()
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.monthly_amount', null)
        ->assertJsonPath('data.category_ids', []);
});

it('saves an explicit monthly amount with a category scope and computes the weekly target', function () {
    [$user, $headers] = $this->userWithAuth();
    [$parent, $child] = foodCategories($user->id);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $child->id,
        'type_transaction' => 'expense',
        'amount' => 150,
        'date_operation' => '2026-11-05',
    ]);

    $this->putJson('/api/shopping/food-budget', [
        'monthly_amount' => 400,
        'shopping_day' => 7,
        'category_ids' => [$parent->id],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.monthly_amount', 400)
        ->assertJsonPath('data.spent', 150)
        ->assertJsonPath('data.available', 250)
        ->assertJsonPath('data.shopping_days_remaining', 3)
        ->assertJsonPath('data.weekly_target', 83.33)
        ->assertJsonPath('data.suggested_amount', 500)
        ->assertJsonPath('data.category_ids.0', $parent->id);

    expect(FoodBudget::where('user_id', $user->id)->sole()->monthly_amount)->toBe('400.00');
});

it('sums spend across every selected top-level category without double-counting children', function () {
    [$user, $headers] = $this->userWithAuth();
    [$food, $market] = foodCategories($user->id);
    $other = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Verdulería',
        'parent_id' => null,
        'type' => 'expense',
        'monthly_budget' => 100,
    ]);
    foreach ([[$market, 120], [$other, 30]] as [$category, $amount]) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type_transaction' => 'expense',
            'amount' => $amount,
            'date_operation' => '2026-11-05',
        ]);
    }

    $this->putJson('/api/shopping/food-budget', [
        'monthly_amount' => 600,
        'shopping_day' => 7,
        'category_ids' => [$food->id, $other->id],
    ], $headers)->assertOk();

    $this->getJson('/api/shopping/food-budget', $headers)
        ->assertOk()
        ->assertJsonPath('data.spent', 150)
        ->assertJsonPath('data.suggested_amount', 600);
});

it('rejects a category from another user or an income category', function () {
    [$user, $headers] = $this->userWithAuth();
    [$foreign] = $this->userWithAuth();

    $foreignCategory = Category::factory()->create([
        'user_id' => $foreign->id,
        'type' => 'expense',
    ]);
    $income = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    $this->putJson('/api/shopping/food-budget', [
        'monthly_amount' => 400,
        'shopping_day' => 7,
        'category_ids' => [$foreignCategory->id],
    ], $headers)->assertStatus(422);

    $this->putJson('/api/shopping/food-budget', [
        'monthly_amount' => 400,
        'shopping_day' => 7,
        'category_ids' => [$income->id],
    ], $headers)->assertStatus(422);
});
