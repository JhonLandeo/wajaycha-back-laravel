<?php

declare(strict_types=1);

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationLinker;
use Illuminate\Support\Facades\DB;

/** Una fila de una fuente dada, con o sin categoria. */
function linkerRow(User $user, SourceType $source, ?Category $category = null, string $amount = '12.50'): Transaction
{
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'amount' => $amount,
        'type_transaction' => 'expense',
        'date_operation' => '2026-09-27 13:09:59',
        'source_type' => $source->value,
        'category_id' => $category?->id,
    ]);
}

/** @param  array<int, array{category_id: int, amount: string}>  $lines */
function linkerSplit(Transaction $transaction, array $lines): void
{
    DB::table('transaction_splits')->insert(array_map(
        static fn (array $line): array => array_merge($line, [
            'transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        $lines,
    ));
}

it('pasa la categoria del satelite a un maestro que no tiene ninguna', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);

    $yape = linkerRow($user, SourceType::IMPORT_APP, $comida);
    $extracto = linkerRow($user, SourceType::IMPORT_STATEMENT);

    [$master, $satellite] = app(ReconciliationLinker::class)->link($yape, $extracto);

    expect($master->id)->toBe($extracto->id)
        ->and($satellite->fresh()->matched_transaction_id)->toBe($extracto->id)
        ->and($extracto->fresh()->category_id)->toBe($comida->id)
        ->and($yape->fresh()->category_id)->toBe($comida->id);
});

it('no pisa la categoria que el maestro ya tiene', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $transporte = Category::factory()->create(['user_id' => $user->id]);

    $yape = linkerRow($user, SourceType::IMPORT_APP, $comida);
    $extracto = linkerRow($user, SourceType::IMPORT_STATEMENT, $transporte);

    app(ReconciliationLinker::class)->link($yape, $extracto);

    expect($extracto->fresh()->category_id)->toBe($transporte->id)
        ->and($yape->fresh()->category_id)->toBe($comida->id);
});

it('deja sin categoria al par cuando ninguno la tiene', function () {
    $user = User::factory()->create();

    $yape = linkerRow($user, SourceType::IMPORT_APP);
    $extracto = linkerRow($user, SourceType::IMPORT_STATEMENT);

    app(ReconciliationLinker::class)->link($yape, $extracto);

    expect($extracto->fresh()->category_id)->toBeNull()
        ->and($yape->fresh()->category_id)->toBeNull();
});

it('adopta el reparto del satelite cuando el maestro no tiene ni categoria ni reparto', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $limpieza = Category::factory()->create(['user_id' => $user->id]);

    $yape = linkerRow($user, SourceType::IMPORT_APP);
    linkerSplit($yape, [
        ['category_id' => $comida->id, 'amount' => '7.50'],
        ['category_id' => $limpieza->id, 'amount' => '5.00'],
    ]);

    $extracto = linkerRow($user, SourceType::IMPORT_STATEMENT);

    app(ReconciliationLinker::class)->link($yape, $extracto);

    $master = $extracto->fresh();
    $masterSplits = DB::table('transaction_splits')->where('transaction_id', $master->id)->get();

    expect($master->category_id)->toBeNull()
        ->and($masterSplits)->toHaveCount(2)
        ->and((float) $masterSplits->sum('amount'))->toBe(12.50)
        // El satelite conserva el suyo: deshacer el enlace lo vuelve a mostrar
        // con la atribucion que el usuario había hecho.
        ->and(DB::table('transaction_splits')->where('transaction_id', $yape->id)->count())->toBe(2);
});

it('no adopta el reparto si el maestro ya tiene categoria', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $transporte = Category::factory()->create(['user_id' => $user->id]);

    $yape = linkerRow($user, SourceType::IMPORT_APP);
    linkerSplit($yape, [
        ['category_id' => $comida->id, 'amount' => '7.50'],
        ['category_id' => $transporte->id, 'amount' => '5.00'],
    ]);

    $extracto = linkerRow($user, SourceType::IMPORT_STATEMENT, $transporte);

    app(ReconciliationLinker::class)->link($yape, $extracto);

    expect($extracto->fresh()->category_id)->toBe($transporte->id)
        ->and(DB::table('transaction_splits')->where('transaction_id', $extracto->id)->count())->toBe(0);
});

it('mantiene el reparto del maestro aunque el satelite traiga una categoria', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $limpieza = Category::factory()->create(['user_id' => $user->id]);

    $extracto = linkerRow($user, SourceType::IMPORT_STATEMENT);
    linkerSplit($extracto, [
        ['category_id' => $comida->id, 'amount' => '7.50'],
        ['category_id' => $limpieza->id, 'amount' => '5.00'],
    ]);

    $yape = linkerRow($user, SourceType::IMPORT_APP, $comida);

    app(ReconciliationLinker::class)->link($yape, $extracto);

    $master = $extracto->fresh();

    expect($master->category_id)->toBeNull()
        ->and(DB::table('transaction_splits')->where('transaction_id', $master->id)->count())->toBe(2);
});

it('no adopta un reparto cuyos montos no suman el monto del maestro', function () {
    $user = User::factory()->create();
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $limpieza = Category::factory()->create(['user_id' => $user->id]);

    $yape = linkerRow($user, SourceType::IMPORT_APP);
    linkerSplit($yape, [
        ['category_id' => $comida->id, 'amount' => '7.50'],
        ['category_id' => $limpieza->id, 'amount' => '5.00'],
    ]);

    $extracto = linkerRow($user, SourceType::IMPORT_STATEMENT, amount: '20.00');

    app(ReconciliationLinker::class)->link($yape, $extracto);

    expect($extracto->fresh()->category_id)->toBeNull()
        ->and(DB::table('transaction_splits')->where('transaction_id', $extracto->id)->count())->toBe(0);
});
