<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * El reparto por categoría es un invariante de la base, no de un servicio.
 *
 * Los triggers son diferidos: la validación ocurre al final de la transacción,
 * no en cada fila, para que un reparto se pueda insertar en varias filas sin
 * que la primera falle por no sumar todavía. Por eso los tests fuerzan las
 * restricciones con `SET CONSTRAINTS ALL IMMEDIATE` antes de afirmar el error.
 *
 * Ver ADR-0050.
 */
function splitTestTransaction(User $user, string $amount, ?int $categoryId = null): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'amount' => $amount,
        'category_id' => $categoryId,
        'type_transaction' => 'expense',
    ]);
}

function splitTestCategory(User $user, string $name): Category
{
    return Category::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'type' => 'expense',
    ]);
}

it('la vista resuelve el monto completo cuando la transacción no tiene reparto', function () {
    $user = User::factory()->create();
    $category = splitTestCategory($user, 'Supermercado');
    $transaction = splitTestTransaction($user, '100.00', $category->id);

    $rows = DB::table('v_transaction_allocations')
        ->where('transaction_id', $transaction->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]->allocated_amount)->toBe(100.0)
        ->and((float) $rows[0]->transaction_amount)->toBe(100.0)
        ->and((int) $rows[0]->category_id)->toBe($category->id);
});

it('la vista entrega una fila por categoría cuando hay reparto', function () {
    $user = User::factory()->create();
    $food = splitTestCategory($user, 'Comida');
    $cleaning = splitTestCategory($user, 'Limpieza');
    $transaction = splitTestTransaction($user, '100.00');

    DB::table('transaction_splits')->insert([
        [
            'transaction_id' => $transaction->id,
            'category_id' => $food->id,
            'user_id' => $user->id,
            'amount' => '60.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'transaction_id' => $transaction->id,
            'category_id' => $cleaning->id,
            'user_id' => $user->id,
            'amount' => '40.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    $rows = DB::table('v_transaction_allocations')
        ->where('transaction_id', $transaction->id)
        ->orderBy('category_id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->sum(fn (object $row): float => (float) $row->allocated_amount))->toBe(100.0)
        ->and($rows->pluck('transaction_amount')->map(fn ($amount): float => (float) $amount)->unique()->all())->toBe([100.0]);
});

it('rechaza un reparto cuya suma no coincide con el movimiento', function () {
    $user = User::factory()->create();
    $food = splitTestCategory($user, 'Comida');
    $cleaning = splitTestCategory($user, 'Limpieza');
    $transaction = splitTestTransaction($user, '100.00');

    DB::table('transaction_splits')->insert([
        [
            'transaction_id' => $transaction->id,
            'category_id' => $food->id,
            'user_id' => $user->id,
            'amount' => '60.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'transaction_id' => $transaction->id,
            'category_id' => $cleaning->id,
            'user_id' => $user->id,
            'amount' => '30.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))
        ->toThrow(QueryException::class);
});

it('rechaza tener category_id y reparto a la vez', function () {
    $user = User::factory()->create();
    $category = splitTestCategory($user, 'Supermercado');
    $transaction = splitTestTransaction($user, '100.00', $category->id);

    DB::table('transaction_splits')->insert([
        'transaction_id' => $transaction->id,
        'category_id' => $category->id,
        'user_id' => $user->id,
        'amount' => '100.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))
        ->toThrow(QueryException::class);
});

it('rechaza un reparto que apunta a la categoría de otro usuario', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $transaction = splitTestTransaction($owner, '100.00');
    $strangersCategory = splitTestCategory($stranger, 'Ajena');

    expect(fn () => DB::table('transaction_splits')->insert([
        'transaction_id' => $transaction->id,
        'category_id' => $strangersCategory->id,
        'user_id' => $owner->id,
        'amount' => '100.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('permite volver a una sola categoría borrando el reparto', function () {
    $user = User::factory()->create();
    $food = splitTestCategory($user, 'Comida');
    $cleaning = splitTestCategory($user, 'Limpieza');
    $transaction = splitTestTransaction($user, '100.00');

    DB::table('transaction_splits')->insert([
        [
            'transaction_id' => $transaction->id,
            'category_id' => $food->id,
            'user_id' => $user->id,
            'amount' => '60.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'transaction_id' => $transaction->id,
            'category_id' => $cleaning->id,
            'user_id' => $user->id,
            'amount' => '40.00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    DB::table('transaction_splits')->where('transaction_id', $transaction->id)->delete();
    DB::table('transactions')->where('id', $transaction->id)->update(['category_id' => $food->id]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    $rows = DB::table('v_transaction_allocations')
        ->where('transaction_id', $transaction->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]->allocated_amount)->toBe(100.0)
        ->and((int) $rows[0]->category_id)->toBe($food->id);
});
