<?php

declare(strict_types=1);

use App\Actions\Shopping\ResolveGroceryCategoryAction;
use App\Models\Category;
use App\Models\GroceryBudgetLink;
use App\Models\User;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * design.md D6, spec.md "Lazy-resolve-then-pin lifecycle". `ResolveGroceryCategoryAction`
 * is the only caller of the name-based lookup — once a pin exists, the name is never
 * read again (step 1 always wins over step 2).
 *
 * `User::factory()->create()` runs `UserObserver` → `SeedDefaultWorkspaceAction`,
 * which seeds `config('onboarding.categories')` for every new account — and that
 * list already contains a leaf category named exactly
 * `config('shopping.grocery_category_name')` ('🛒 Supermercado', under
 * '🍽️ Alimentación'). This is the realistic case this feature is built around:
 * most users already have the category before they ever touch this endpoint.
 */
function seededGroceryCategory(User $user): Category
{
    /** @var Category */
    return Category::where('user_id', $user->id)
        ->where('name', config('shopping.grocery_category_name'))
        ->sole();
}

it('resolves the grocery category by name once and pins it', function () {
    $user = User::factory()->create();
    $category = seededGroceryCategory($user);

    $link = app(ResolveGroceryCategoryAction::class)->execute($user->id);

    expect($link)->not->toBeNull()
        ->and($link->category_id)->toBe($category->id)
        ->and($link->resolved_by)->toBe('auto')
        ->and($link->linked_at)->not->toBeNull();

    expect(GroceryBudgetLink::where('user_id', $user->id)->count())->toBe(1);
});

it('never re-reads the name once a pin exists — a rename is irrelevant', function () {
    $user = User::factory()->create();
    $category = seededGroceryCategory($user);

    $first = app(ResolveGroceryCategoryAction::class)->execute($user->id);

    $category->update(['name' => 'Ya no es el supermercado']);

    $second = app(ResolveGroceryCategoryAction::class)->execute($user->id);

    expect($second->id)->toBe($first->id)
        ->and($second->category_id)->toBe($category->id)
        ->and($second->resolved_by)->toBe('auto');

    expect(GroceryBudgetLink::where('user_id', $user->id)->count())->toBe(1);
});

it('stays absent and writes nothing when no category matches the configured name', function () {
    $user = User::factory()->create();
    // Simulates the realistic "renamed before first use" case: the onboarding
    // seed exists, but no longer matches the configured name exactly.
    seededGroceryCategory($user)->update(['name' => 'Super Renombrado']);

    $link = app(ResolveGroceryCategoryAction::class)->execute($user->id);

    expect($link)->toBeNull()
        ->and(GroceryBudgetLink::where('user_id', $user->id)->exists())->toBeFalse();
});

it('absorbs two concurrent resolution attempts through the unique index, both returning the same pin', function () {
    $user = User::factory()->create();
    $category = seededGroceryCategory($user);

    /** @var GroceryBudgetRepositoryContract $repository */
    $repository = app(GroceryBudgetRepositoryContract::class);

    // Neither call checks for an existing row first — both "had resolved the
    // same name", simulating the race design.md D6 describes. The unique
    // index (unq_grocery_budget_links_user_id) must be the arbiter, not
    // application logic: only one row survives either way.
    $first = $repository->pin($user->id, $category->id, 'auto');
    $second = $repository->pin($user->id, $category->id, 'auto');

    expect($first->category_id)->toBe($category->id)
        ->and($second->category_id)->toBe($category->id)
        ->and(GroceryBudgetLink::where('user_id', $user->id)->count())->toBe(1);
});
