<?php

declare(strict_types=1);

/**
 * `budgetedExpenseSnapshotsForMonth()` is the complement of
 * `expenseBudgetSnapshotsForMonth()`, and the cases below exist because the two
 * filters are opposites that are easy to conflate: one keeps categories with
 * spend, this one keeps categories with a budget.
 *
 * The distinction is not academic. The daily allowance divides what is left by
 * the days that are left, so an untouched budget is the category with the MOST
 * room — and inheriting the other method's `spent > 0` filter would silently
 * understate what the user can spend, which is the one direction that figure must
 * never be wrong in.
 */

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\Enums\BudgetPeriod;
use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CategoryRepository;

function spendOn(User $user, Category $category, float $amount): void
{
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => $amount,
        'date_operation' => now()->toDateTimeString(),
    ]);
}

function budgetedSnapshots(User $user): array
{
    return (new CategoryRepository)->budgetedExpenseSnapshotsForMonth(
        $user->id,
        (int) now()->format('n'),
        (int) now()->format('Y'),
    );
}

it('devuelve una categoria presupuestada aunque nadie haya gastado en ella', function () {
    $user = User::factory()->create();
    Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Comida',
        'monthly_budget' => 400,
    ]);

    $snapshots = budgetedSnapshots($user);

    expect($snapshots)->toHaveCount(1)
        ->and($snapshots[0])->toBeInstanceOf(CategoryMonthSnapshot::class)
        ->and($snapshots[0]->name)->toBe('Comida')
        ->and($snapshots[0]->monthlyBudget)->toBe(400.0)
        ->and($snapshots[0]->spent)->toBe(0.0);
});

it('lee el gasto del mes cuando lo hay', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 400,
    ]);
    spendOn($user, $comida, 150);

    expect(budgetedSnapshots($user)[0]->spent)->toBe(150.0);
});

/**
 * The inverse of the case above, and the reason this method is not just the other
 * one with a flag: an unbudgeted category with spend is blindness, a different
 * question with a different owner. There is no daily figure to derive from it.
 */
it('deja afuera una categoria con gasto y sin presupuesto', function () {
    $user = User::factory()->create();
    $sinPresupuesto = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 0,
    ]);
    spendOn($user, $sinPresupuesto, 80);

    expect(budgetedSnapshots($user))->toBe([]);
});

it('deja afuera ingresos y transferencias', function () {
    $user = User::factory()->create();
    $gasto = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'monthly_budget' => 300]);
    Category::factory()->create(['user_id' => $user->id, 'type' => 'income', 'monthly_budget' => 5000]);
    Category::factory()->create(['user_id' => $user->id, 'type' => 'transfer', 'monthly_budget' => 900]);

    $snapshots = budgetedSnapshots($user);

    expect($snapshots)->toHaveCount(1)
        ->and($snapshots[0]->categoryId)->toBe($gasto->id);
});

/**
 * Carried, not filtered. The repository has no opinion about envelopes — it
 * reports the unit and lets `DailyAllowanceCalculator` decide, which is where that
 * judgement is documented and tested.
 */
it('trae el periodo del presupuesto para que el que decide pueda decidir', function () {
    $user = User::factory()->create();
    Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Seguros',
        'monthly_budget' => 1200,
        'budget_period' => BudgetPeriod::YEARLY->value,
    ]);

    expect(budgetedSnapshots($user)[0]->budgetPeriod)->toBe(BudgetPeriod::YEARLY);
});

/**
 * `largestExpenseAmount` and `spentInYear` feed pace decisions this question does
 * not make. They are left at zero deliberately, and fetching them would be two
 * round trips bought for figures nobody reads.
 */
it('no paga las consultas que solo sirven para el ritmo', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'monthly_budget' => 400]);
    spendOn($user, $comida, 150);

    $snapshot = budgetedSnapshots($user)[0];

    expect($snapshot->largestExpenseAmount)->toBe(0.0)
        ->and($snapshot->spentInYear)->toBe(0.0);
});

it('no mezcla las categorias de dos usuarios', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'monthly_budget' => 300]);
    Category::factory()->create(['user_id' => $otro->id, 'type' => 'expense', 'monthly_budget' => 900]);

    expect(budgetedSnapshots($user))->toHaveCount(1)
        ->and(budgetedSnapshots($user)[0]->monthlyBudget)->toBe(300.0);
});

it('devuelve vacio cuando el usuario no tiene presupuestos', function () {
    expect(budgetedSnapshots(User::factory()->create()))->toBe([]);
});
