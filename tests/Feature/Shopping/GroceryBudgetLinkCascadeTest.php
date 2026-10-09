<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\GroceryBudgetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The physical delete case design.md D6/M4 depends on: `App\Models\Category`
 * carries no `SoftDeletes`, so `Category::delete()` here is a real DELETE and
 * the composite `fk_grocery_budget_links_category_id ... ON DELETE CASCADE`
 * (verified structurally in ShoppingSchemaTest) is what must make it succeed
 * and remove the pin, rather than a `NO ACTION` constraint error the SPA
 * cannot explain.
 */
it('deletes the pinned category and cascades the grocery_budget_links row away', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $link = GroceryBudgetLink::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'resolved_by' => 'auto',
    ]);

    $category->delete();

    expect(Category::find($category->id))->toBeNull()
        ->and(GroceryBudgetLink::find($link->id))->toBeNull();
});

it('returns to no-link after the pinned category is deleted', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    GroceryBudgetLink::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'resolved_by' => 'auto',
    ]);

    $category->delete();

    expect(GroceryBudgetLink::where('user_id', $user->id)->exists())->toBeFalse();
});
