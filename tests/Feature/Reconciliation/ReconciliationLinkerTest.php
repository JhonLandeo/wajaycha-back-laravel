<?php

declare(strict_types=1);

use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationLinker;

/** Una fila de una fuente dada, con o sin categoria. */
function linkerRow(User $user, SourceType $source, ?Category $category = null): Transaction
{
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'amount' => '12.50',
        'type_transaction' => 'expense',
        'date_operation' => '2026-09-27 13:09:59',
        'source_type' => $source->value,
        'category_id' => $category?->id,
    ]);
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
