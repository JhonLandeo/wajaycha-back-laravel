<?php

declare(strict_types=1);

/**
 * `expenseByCategoryBetween()` against a real database, because the two things
 * that can go wrong here are both invisible in a unit test: which rows the view
 * hides, and whether the interval is half-open.
 */

use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepository;
use Carbon\CarbonImmutable;

function expenseAt(User $user, ?Category $category, float $amount, string $date, array $extra = []): Transaction
{
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category?->id,
        'type_transaction' => 'expense',
        'amount' => $amount,
        'date_operation' => $date,
    ], $extra));
}

function expensesBetween(User $user, string $from, string $to): array
{
    return (new TransactionRepository)->expenseByCategoryBetween(
        $user->id,
        CarbonImmutable::parse($from, 'America/Lima'),
        CarbonImmutable::parse($to, 'America/Lima'),
    );
}

it('suma el gasto por categoria dentro de la ventana', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Comida']);

    expenseAt($user, $comida, 100.0, '2026-06-03 10:00:00');
    expenseAt($user, $comida, 50.0, '2026-06-09 10:00:00');

    $rows = expensesBetween($user, '2026-06-01', '2026-06-17');

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->category_id)->toBe($comida->id)
        ->and($rows[0]->category_name)->toBe('Comida')
        ->and((float) $rows[0]->total)->toBe(150.0);
});

/**
 * Half-open: the lower bound is inside and the upper bound is not. An inclusive
 * upper bound would count the first day of the next window twice — once in each
 * of the two windows a comparison subtracts.
 */
it('el intervalo incluye el arranque y excluye el cierre', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    expenseAt($user, $comida, 10.0, '2026-06-01 00:00:00');
    expenseAt($user, $comida, 999.0, '2026-06-17 00:00:00');

    $rows = expensesBetween($user, '2026-06-01', '2026-06-17');

    expect((float) $rows[0]->total)->toBe(10.0);
});

it('no cuenta ingresos', function () {
    $user = User::factory()->create();
    $sueldo = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    expenseAt($user, $sueldo, 5000.0, '2026-06-03 10:00:00', ['type_transaction' => 'income']);

    expect(expensesBetween($user, '2026-06-01', '2026-06-17'))->toBe([]);
});

/**
 * The reason this reads `v_unified_transactions` and never `transactions`: the
 * view keeps only rows with `matched_transaction_id IS NULL`, so a movement that
 * arrived through both Yape and the bank statement counts once. Reading the table
 * directly would inflate a month, and comparing two inflated months invents a
 * change that never happened.
 */
it('no cuenta dos veces un movimiento ya conciliado', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $master = expenseAt($user, $comida, 80.0, '2026-06-03 10:00:00');
    expenseAt($user, $comida, 80.0, '2026-06-03 10:00:00', ['matched_transaction_id' => $master->id]);

    expect((float) expensesBetween($user, '2026-06-01', '2026-06-17')[0]->total)->toBe(80.0);
});

/**
 * A LEFT JOIN, not an INNER. An uncategorised row surviving with a null name is
 * the whole point: dropping it would make the comparison quietly smaller than the
 * ledger it claims to describe.
 */
it('devuelve el gasto sin categoria con nombre nulo', function () {
    $user = User::factory()->create();

    expenseAt($user, null, 300.0, '2026-06-03 10:00:00');

    $rows = expensesBetween($user, '2026-06-01', '2026-06-17');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->category_id)->toBeNull()
        ->and($rows[0]->category_name)->toBeNull()
        ->and((float) $rows[0]->total)->toBe(300.0);
});

it('no mezcla el gasto de dos usuarios', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    expenseAt($user, Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']), 100.0, '2026-06-03 10:00:00');
    expenseAt($otro, Category::factory()->create(['user_id' => $otro->id, 'type' => 'expense']), 900.0, '2026-06-03 10:00:00');

    $rows = expensesBetween($user, '2026-06-01', '2026-06-17');

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]->total)->toBe(100.0);
});

it('devuelve vacio cuando no hay nada en la ventana', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    expenseAt($user, $comida, 100.0, '2026-05-03 10:00:00');

    expect(expensesBetween($user, '2026-06-01', '2026-06-17'))->toBe([]);
});
