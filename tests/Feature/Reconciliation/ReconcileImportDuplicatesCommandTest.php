<?php

declare(strict_types=1);

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Models\Detail;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Escribe una fila de importacion tal cual la dejo el importador con el defecto:
 * el mismo movimiento repetido, a veces con el mensaje en null y a veces en
 * cadena vacia.
 */
function importedRow(User $user, Detail $detail, string $amount, string $at, ?string $message = null): Transaction
{
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => $amount,
        'type_transaction' => 'expense',
        'date_operation' => $at,
        'message' => $message,
        'source_type' => SourceType::IMPORT_APP->value,
        'is_manual' => false,
    ]);
}

function merchant(User $user, string $description = 'PANADERIA SAN MARTIN'): Detail
{
    return Detail::factory()->create(['user_id' => $user->id, 'description' => $description]);
}

it('informa sin tocar nada mientras no se pase --apply', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $primera = importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');
    $repetida = importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');

    $this->artisan('transactions:reconcile-import-duplicates')
        ->expectsOutputToContain('S/ 29.90')
        ->assertSuccessful();

    // Correrlo sin querer no puede cambiar lo que suman los reportes de nadie.
    expect($primera->fresh()->matched_transaction_id)->toBeNull()
        ->and($repetida->fresh()->matched_transaction_id)->toBeNull()
        ->and(ReconciliationCandidate::count())->toBe(0);
});

it('concilia la copia y conserva las dos filas', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $primera = importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');
    $repetida = importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect(Transaction::count())->toBe(2)
        ->and($primera->fresh()->matched_transaction_id)->toBeNull()
        ->and($repetida->fresh()->matched_transaction_id)->toBe($primera->id);
});

it('deja el rastro que permite deshacerlo', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');
    importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    // Toda la limpieza aparece en la misma lista de "unificados automaticamente"
    // que el resto, y se revierte de a un par.
    $candidate = ReconciliationCandidate::sole();

    expect($candidate->status)->toBe(ReconciliationStatus::CONFIRMED)
        ->and($candidate->resolved_by)->toBe(ResolvedBy::SYSTEM);
});

it('empareja el null con la cadena vacia', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    // Exportaciones de distinta version escriben la ausencia de nota distinto.
    $primera = importedRow($user, $detail, '18.00', '2026-07-05 07:20:00', null);
    $repetida = importedRow($user, $detail, '18.00', '2026-07-05 07:20:09', '');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($repetida->fresh()->matched_transaction_id)->toBe($primera->id);
});

it('alcanza al par que cruza el borde del minuto', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    // Seis segundos de distancia y dos minutos distintos. Agrupar por minuto
    // perdia exactamente este caso -- uno de los 41 pares reales.
    $primera = importedRow($user, $detail, '45.00', '2026-05-10 14:30:55');
    $repetida = importedRow($user, $detail, '45.00', '2026-05-10 14:31:05');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($repetida->fresh()->matched_transaction_id)->toBe($primera->id);
});

it('colapsa un grupo de tres sobre un solo sobreviviente', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $primera = importedRow($user, $detail, '350.00', '2026-05-22 18:02:00');
    $segunda = importedRow($user, $detail, '350.00', '2026-05-22 18:02:00');
    $tercera = importedRow($user, $detail, '350.00', '2026-05-22 18:02:30');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    // Nunca una cadena: `fn_get_transactions` cuenta los que tienen
    // `matched_transaction_id` en null, asi que un satelite apuntando a otro
    // satelite sacaria al del extremo de los totales por completo.
    expect($primera->fresh()->matched_transaction_id)->toBeNull()
        ->and($segunda->fresh()->matched_transaction_id)->toBe($primera->id)
        ->and($tercera->fresh()->matched_transaction_id)->toBe($primera->id);
});

it('no toca dos pagos separados por mas de la tolerancia', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $primera = importedRow($user, $detail, '18.00', '2026-07-05 07:20:00');
    $otra = importedRow($user, $detail, '18.00', '2026-07-05 07:21:01');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($primera->fresh()->matched_transaction_id)->toBeNull()
        ->and($otra->fresh()->matched_transaction_id)->toBeNull();
});

it('no toca pagos con notas distintas ni de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $detail = merchant($user);

    $almuerzo = importedRow($user, $detail, '18.00', '2026-07-05 07:20:00', 'almuerzo');
    $cena = importedRow($user, $detail, '18.00', '2026-07-05 07:20:10', 'cena');
    $ajeno = importedRow($otro, merchant($otro), '18.00', '2026-07-05 07:20:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($almuerzo->fresh()->matched_transaction_id)->toBeNull()
        ->and($cena->fresh()->matched_transaction_id)->toBeNull()
        ->and($ajeno->fresh()->matched_transaction_id)->toBeNull();
});

it('no vuelve a unir lo que el usuario ya separo', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $primera = importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');
    $repetida = importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');

    // El usuario ya dijo que son dos pagos distintos.
    ReconciliationCandidate::create([
        'user_id' => $user->id,
        'transaction_id' => $primera->id,
        'candidate_transaction_id' => $repetida->id,
        'status' => ReconciliationStatus::REJECTED,
        'resolved_by' => ResolvedBy::USER,
        'resolved_at' => now(),
    ]);

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($repetida->fresh()->matched_transaction_id)->toBeNull()
        ->and(ReconciliationCandidate::count())->toBe(1);
});

it('es idempotente', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');
    importedRow($user, $detail, '29.90', '2026-07-08 14:52:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();
    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect(ReconciliationCandidate::count())->toBe(1)
        ->and(Transaction::whereNotNull('matched_transaction_id')->count())->toBe(1);
});
