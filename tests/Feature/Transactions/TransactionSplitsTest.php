<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * La escritura del reparto por categoría (ADR-0050, Fase 2).
 *
 * Lo que se prueba es la puerta HTTP completa: validación, exclusión mutua con
 * `category_id`, rechazo de movimientos conciliados y la vuelta explícita a una
 * sola categoría. La aritmética de los reportes ya vive en
 * `TransactionAllocationReadersTest`.
 */
function splitUser(): User
{
    return User::factory()->create();
}

function splitCategories(User $user): array
{
    return [
        Category::factory()->create(['user_id' => $user->id, 'name' => 'Comida', 'type' => 'expense']),
        Category::factory()->create(['user_id' => $user->id, 'name' => 'Limpieza', 'type' => 'expense']),
    ];
}

function splitTransactionFor(User $user, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'category_id' => null,
        'type_transaction' => 'expense',
        'amount' => 100,
        'is_manual' => true,
        'date_operation' => '2026-08-10 10:00:00',
    ], $overrides));
}

it('reparte un movimiento y anula su categoría única', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user, ['category_id' => $food->id]);
    $headers = $this->actingAsJwtUser($user);

    $response = $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers);

    $response->assertStatus(200);

    $payload = $response->json();
    $byCategory = collect($payload['splits'])->keyBy('category_id');

    expect($payload['category_id'])->toBeNull()
        ->and((float) $byCategory[$food->id]['amount'])->toBe(60.0)
        ->and((float) $byCategory[$cleaning->id]['amount'])->toBe(40.0)
        ->and($transaction->fresh()->category_id)->toBeNull()
        ->and(DB::table('transaction_splits')->where('transaction_id', $transaction->id)->count())->toBe(2);
});

it('rechaza un reparto cuya suma no coincide con el movimiento', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $response = $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 30],
        ],
    ], $headers);

    $response->assertStatus(422)->assertJsonValidationErrors('splits');

    expect(DB::table('transaction_splits')->where('transaction_id', $transaction->id)->count())->toBe(0);
});

it('rechaza una categoría repetida en el reparto', function () {
    $user = splitUser();
    [$food] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $response = $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $food->id, 'amount' => 40],
        ],
    ], $headers);

    $response->assertStatus(422)->assertJsonValidationErrors('splits.0.category_id');
});

it('rechaza una categoría de otro usuario', function () {
    $user = splitUser();
    $stranger = splitUser();
    [$food] = splitCategories($user);
    $foreign = Category::factory()->create(['user_id' => $stranger->id]);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $response = $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 50],
            ['category_id' => $foreign->id, 'amount' => 50],
        ],
    ], $headers);

    $response->assertStatus(422)->assertJsonValidationErrors('splits.1.category_id');
});

it('rechaza repartir un movimiento conciliado', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $master = splitTransactionFor($user);
    $satellite = splitTransactionFor($user, ['matched_transaction_id' => $master->id]);
    $headers = $this->actingAsJwtUser($user);

    $response = $this->putJson("/api/transactions/{$satellite->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers);

    $response->assertStatus(422)->assertJsonValidationErrors('transaction');
});

it('no deja repartir el movimiento de otro usuario', function () {
    $user = splitUser();
    $stranger = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $foreignTransaction = splitTransactionFor($stranger);
    $headers = $this->actingAsJwtUser($user);

    $response = $this->putJson("/api/transactions/{$foreignTransaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers);

    $response->assertStatus(404);
});

it('vuelve a una sola categoría borrando el reparto', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers)->assertStatus(200);

    $response = $this->deleteJson("/api/transactions/{$transaction->id}/splits", [
        'category_id' => $food->id,
    ], $headers);

    $response->assertStatus(200);

    expect($transaction->fresh()->category_id)->toBe($food->id)
        ->and(DB::table('transaction_splits')->where('transaction_id', $transaction->id)->count())->toBe(0);
});

it('borra el reparto y deja el movimiento sin categoría', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers)->assertStatus(200);

    $this->deleteJson("/api/transactions/{$transaction->id}/splits", [], $headers)->assertStatus(200);

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and(DB::table('transaction_splits')->where('transaction_id', $transaction->id)->count())->toBe(0);
});

it('exige deshacer el reparto antes de asignar una sola categoría', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers)->assertStatus(200);

    $response = $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 100,
        'date_operation' => '2026-08-10 10:00:00',
        'type_transaction' => 'expense',
        'category_id' => $food->id,
    ], $headers);

    $response->assertStatus(422)->assertJsonValidationErrors('category_id');

    expect(DB::table('transaction_splits')->where('transaction_id', $transaction->id)->count())->toBe(2);
});

it('exige ajustar el reparto antes de cambiar el monto de un movimiento manual', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers)->assertStatus(200);

    $response = $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 200,
        'date_operation' => '2026-08-10 10:00:00',
        'type_transaction' => 'expense',
    ], $headers);

    $response->assertStatus(422)->assertJsonValidationErrors('amount');

    expect($transaction->fresh()->amount)->toBe('100.00')
        ->and(DB::table('transaction_splits')->where('transaction_id', $transaction->id)->count())->toBe(2);
});

it('conserva el reparto al editar datos que no son el monto ni la categoría', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers)->assertStatus(200);

    $response = $this->putJson("/api/transactions/{$transaction->id}", [
        'amount' => 100,
        'date_operation' => '2026-08-11 10:00:00',
        'type_transaction' => 'expense',
    ], $headers);

    $response->assertStatus(200);

    expect(DB::table('transaction_splits')->where('transaction_id', $transaction->id)->count())->toBe(2);
});

it('muestra el reparto al consultar el movimiento', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $headers = $this->actingAsJwtUser($user);

    $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers)->assertStatus(200);

    $response = $this->getJson("/api/transactions/{$transaction->id}", $headers);

    $response->assertStatus(200)->assertJsonCount(2, 'splits');

    $byCategory = collect($response->json('splits'))->keyBy('category_id');

    expect((float) $byCategory[$food->id]['amount'])->toBe(60.0)
        ->and($byCategory[$food->id]['category']['name'])->toBe('Comida');
});

it('marca el reparto en el listado de movimientos', function () {
    $user = splitUser();
    [$food, $cleaning] = splitCategories($user);
    $transaction = splitTransactionFor($user);
    $plain = splitTransactionFor($user, ['amount' => 20, 'date_operation' => '2026-08-11 10:00:00']);
    $headers = $this->actingAsJwtUser($user);

    $this->putJson("/api/transactions/{$transaction->id}/splits", [
        'splits' => [
            ['category_id' => $food->id, 'amount' => 60],
            ['category_id' => $cleaning->id, 'amount' => 40],
        ],
    ], $headers)->assertStatus(200);

    $response = $this->getJson('/api/transactions?year=2026&month=8&per_page=50', $headers);
    $response->assertStatus(200);

    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows[$transaction->id]['has_splits'])->toBeTrue()
        ->and($rows[$plain->id]['has_splits'])->toBeFalse();
});
