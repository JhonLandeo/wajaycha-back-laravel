<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\GroceryBudgetLink;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * design.md D2, D5, spec.md "Ceiling is absent, exceeded, or present — never
 * zero". Exercises `GroceryBudgetRepositoryContract::ceilingFor()` directly:
 * null stands for `unlinked`, a returned DTO with `monthlyBudget <= 0` is
 * what the caller reads as `unbudgeted`, and `monthlyBudget > 0` is `set`.
 * The three-state decision itself belongs to the caller (design.md D5) —
 * this repository method only ever answers "is there a resolvable pin, and
 * what does it carry".
 */
it('reports no ceiling when the user has no link', function () {
    $user = User::factory()->create();

    /** @var GroceryBudgetRepositoryContract $repository */
    $repository = app(GroceryBudgetRepositoryContract::class);

    expect($repository->ceilingFor($user->id, CarbonImmutable::now()))->toBeNull();
});

it('reports unbudgeted when the pinned category carries no monthly_budget', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'monthly_budget' => 0,
    ]);
    GroceryBudgetLink::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'resolved_by' => 'auto',
    ]);

    /** @var GroceryBudgetRepositoryContract $repository */
    $repository = app(GroceryBudgetRepositoryContract::class);
    $ceiling = $repository->ceilingFor($user->id, CarbonImmutable::now());

    expect($ceiling)->not->toBeNull()
        ->and($ceiling->monthlyBudget)->toBe(0.0)
        ->and($ceiling->categoryId)->toBe($category->id);
});

it('reports the budgeted ceiling with spend read from v_unified_transactions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'monthly_budget' => 500,
    ]);
    GroceryBudgetLink::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'resolved_by' => 'auto',
    ]);

    $asOf = CarbonImmutable::now();
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 150.00,
        'date_operation' => $asOf->startOfMonth()->addDays(2),
    ]);

    /** @var GroceryBudgetRepositoryContract $repository */
    $repository = app(GroceryBudgetRepositoryContract::class);
    $ceiling = $repository->ceilingFor($user->id, $asOf);

    expect($ceiling)->not->toBeNull()
        ->and($ceiling->monthlyBudget)->toBe(500.0)
        ->and($ceiling->spent)->toBe(150.0)
        ->and($ceiling->categoryName)->toBe($category->name);
});

it('counts a reconciled Yape/bank pair once, reading v_unified_transactions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'monthly_budget' => 500,
    ]);
    GroceryBudgetLink::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'resolved_by' => 'auto',
    ]);

    $asOf = CarbonImmutable::now();
    $bankLeg = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 100.00,
        'date_operation' => $asOf->startOfMonth()->addDays(3),
    ]);
    // The Yape leg that got matched into the bank transaction above.
    // v_unified_transactions filters WHERE matched_transaction_id IS NULL, so
    // this row must not add to the total.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 100.00,
        'date_operation' => $asOf->startOfMonth()->addDays(3),
        'matched_transaction_id' => $bankLeg->id,
    ]);

    /** @var GroceryBudgetRepositoryContract $repository */
    $repository = app(GroceryBudgetRepositoryContract::class);
    $ceiling = $repository->ceilingFor($user->id, $asOf);

    expect($ceiling)->not->toBeNull()
        ->and($ceiling->spent)->toBe(100.0);
});
