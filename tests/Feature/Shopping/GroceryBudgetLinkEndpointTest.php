<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\GroceryBudgetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/shopping/grocery-budget-link')->assertStatus(401);
});

/**
 * `User::factory()->create()` seeds a leaf category named exactly
 * `config('shopping.grocery_category_name')` via onboarding (see
 * GroceryCategoryResolutionTest.php). Renaming it away first is what makes
 * the FIRST call genuinely `unlinked` — the realistic case is that most
 * users already have the match, which the second half of this test proves.
 */
it('reports unlinked on the first call, then auto-pins once a match exists', function () {
    [$user, $headers] = $this->userWithAuth();
    $category = Category::where('user_id', $user->id)
        ->where('name', config('shopping.grocery_category_name'))
        ->sole();
    $category->update(['name' => 'Aun no coincide']);

    $first = $this->getJson('/api/shopping/grocery-budget-link', $headers)->assertOk();
    expect($first->json('data.ceiling_state'))->toBe('unlinked');
    expect(GroceryBudgetLink::where('user_id', $user->id)->exists())->toBeFalse();

    $category->update(['name' => config('shopping.grocery_category_name'), 'monthly_budget' => 300]);

    $second = $this->getJson('/api/shopping/grocery-budget-link', $headers)->assertOk();
    expect($second->json('data.ceiling_state'))->toBe('set')
        ->and($second->json('data.category_id'))->toBe($category->id);

    $link = GroceryBudgetLink::where('user_id', $user->id)->sole();
    expect($link->category_id)->toBe($category->id)
        ->and($link->resolved_by)->toBe('auto');
});

it('upserts a manual pin on PUT, and a later GET never re-resolves by name', function () {
    [$user, $headers] = $this->userWithAuth();
    $manualCategory = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Mi categoria elegida',
        'monthly_budget' => 200,
    ]);

    $this->putJson('/api/shopping/grocery-budget-link', [
        'category_id' => $manualCategory->id,
    ], $headers)->assertOk();

    $link = GroceryBudgetLink::where('user_id', $user->id)->sole();
    expect($link->category_id)->toBe($manualCategory->id)
        ->and($link->resolved_by)->toBe('manual');

    // Renaming the onboarding-seeded '🛒 Supermercado' category away and back
    // would be irrelevant either way — step 1 (findLink) always wins once a
    // pin exists, manual or not. A later GET must keep reading the manual pin.
    $response = $this->getJson('/api/shopping/grocery-budget-link', $headers)->assertOk();
    expect($response->json('data.category_id'))->toBe($manualCategory->id);

    expect(GroceryBudgetLink::where('user_id', $user->id)->count())->toBe(1);
});
