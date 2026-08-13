<?php

declare(strict_types=1);

use App\Enums\SourceType;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function transactionWithSource(string $sourceType): Transaction
{
    $user = User::factory()->create();

    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'amount' => '10.00',
        'type_transaction' => 'expense',
        'date_operation' => '2026-08-10 14:30:00',
        'source_type' => $sourceType,
    ]);
}

it('rechaza un source_type que el dominio no reconoce', function () {
    expect(fn () => transactionWithSource('yape_app'))->toThrow(QueryException::class);
});

it('rechaza tambien lo que entre por SQL directo', function () {
    $transaction = transactionWithSource(SourceType::IMPORT_APP->value);

    // El enum no llega hasta aca, y ese es justamente el punto: la garantia tiene
    // que vivir en la tabla, no en el codigo que la usa.
    expect(fn () => DB::statement(
        'UPDATE transactions SET source_type = ? WHERE id = ?',
        ['cualquier_cosa', $transaction->id]
    ))->toThrow(QueryException::class);
});

it('acepta todos los valores que el enum sabe expresar', function () {
    foreach (SourceType::cases() as $case) {
        expect(transactionWithSource($case->value)->source_type)->toBe($case->value);
    }
});

it('deja el default de la columna dentro de lo permitido', function () {
    $user = User::factory()->create();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'amount' => '10.00',
        'type_transaction' => 'expense',
        'date_operation' => '2026-08-10 14:30:00',
    ]);

    expect($transaction->fresh()->source_type)->toBe(SourceType::MANUAL->value);
});
