<?php

declare(strict_types=1);

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\Exceptions\Coaching\TooManyCategoriesException;
use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CategoryRepository;
use Illuminate\Support\Carbon;

function makeExpenseTransaction(User $user, Category $category, float $amount, ?Carbon $when = null): Transaction
{
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    return Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => $amount,
        'date_operation' => ($when ?? now())->toDateTimeString(),
    ]);
}

it('returns snapshots shaped for the pace evaluator, one per surviving category', function () {
    $user = User::factory()->create();
    $groceries = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Comida',
        'monthly_budget' => 400,
    ]);
    makeExpenseTransaction($user, $groceries, 150);

    $repository = new CategoryRepository;
    $snapshots = $repository->expenseBudgetSnapshotsForMonth($user->id, (int) now()->format('n'), (int) now()->format('Y'));

    expect($snapshots)->toBeArray()->toHaveCount(1);
    expect($snapshots[0])->toBeInstanceOf(CategoryMonthSnapshot::class);
    expect($snapshots[0]->categoryId)->toBe($groceries->id);
    expect($snapshots[0]->name)->toBe('Comida');
    expect($snapshots[0]->type)->toBe('expense');
    expect($snapshots[0]->monthlyBudget)->toBe(400.0);
    expect($snapshots[0]->spent)->toBe(150.0);
    expect($snapshots[0]->largestExpenseAmount)->toBe(150.0);
});

it('excludes income and transfer categories from the returned snapshots', function () {
    $user = User::factory()->create();
    $expenseCategory = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'monthly_budget' => 300]);
    $incomeCategory = Category::factory()->create(['user_id' => $user->id, 'type' => 'income', 'monthly_budget' => 0]);
    $transferCategory = Category::factory()->create(['user_id' => $user->id, 'type' => 'transfer', 'monthly_budget' => 0]);

    makeExpenseTransaction($user, $expenseCategory, 50);
    makeExpenseTransaction($user, $incomeCategory, 999);
    makeExpenseTransaction($user, $transferCategory, 999);

    $repository = new CategoryRepository;
    $snapshots = $repository->expenseBudgetSnapshotsForMonth($user->id, (int) now()->format('n'), (int) now()->format('Y'));

    expect($snapshots)->toHaveCount(1);
    expect($snapshots[0]->categoryId)->toBe($expenseCategory->id);
});

it('includes an expense category with monthly_budget = 0 when it has spend, for phase 4 blindness detection', function () {
    $user = User::factory()->create();
    $unbudgeted = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'monthly_budget' => 0]);
    makeExpenseTransaction($user, $unbudgeted, 80);

    $repository = new CategoryRepository;
    $snapshots = $repository->expenseBudgetSnapshotsForMonth($user->id, (int) now()->format('n'), (int) now()->format('Y'));

    expect($snapshots)->toHaveCount(1);
    expect($snapshots[0]->categoryId)->toBe($unbudgeted->id);
    expect($snapshots[0]->monthlyBudget)->toBe(0.0);
});

it('returns leaf categories only, never a parent that only aggregates children', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'monthly_budget' => 1000]);
    $child = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 200,
        'parent_id' => $parent->id,
    ]);
    makeExpenseTransaction($user, $child, 60);
    // El padre recibe gasto PROPIO. Sin esto el test no prueba nada: un padre sin
    // gasto lo descarta el filtro de spent > 0 antes de que la regla de hojas
    // llegue a opinar, y pasaria igual aunque esa regla no existiera.
    makeExpenseTransaction($user, $parent, 90);

    $repository = new CategoryRepository;
    $snapshots = $repository->expenseBudgetSnapshotsForMonth($user->id, (int) now()->format('n'), (int) now()->format('Y'));

    $returnedIds = array_map(fn (CategoryMonthSnapshot $s) => $s->categoryId, $snapshots);

    expect($returnedIds)->toContain($child->id);
    expect($returnedIds)->not->toContain($parent->id);
});

it('throws when total_records exceeds the configured category ceiling instead of silently truncating', function () {
    config(['coaching.max_categories' => 2]);

    $user = User::factory()->create();
    Category::factory()->count(3)->create(['user_id' => $user->id, 'type' => 'expense', 'monthly_budget' => 100]);

    $repository = new CategoryRepository;

    expect(fn () => $repository->expenseBudgetSnapshotsForMonth($user->id, (int) now()->format('n'), (int) now()->format('Y')))
        ->toThrow(TooManyCategoriesException::class);
});
