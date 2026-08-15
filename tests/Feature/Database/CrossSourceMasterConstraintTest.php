<?php

declare(strict_types=1);

/**
 * Lo que se prueba aca no es el detector: es la BASE.
 *
 * El filtro que impide que dos pagos se cuelguen del mismo asiento vive en una
 * consulta, y una consulta no es una garantia — dos importaciones simultaneas
 * pueden leer las dos que el asiento esta libre, y hay tres lugares que escriben
 * `matched_transaction_id` sin pasar por ella. Por eso estos casos insertan a mano,
 * salteando todo el codigo de aplicacion: si la base los acepta, la garantia no
 * existe por mas prolijo que sea el servicio.
 */

use App\Enums\ReconciliationKind;
use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function constraintMovement(User $user, SourceType $source): Transaction
{
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'amount' => '5.00',
        'type_transaction' => 'income',
        'date_operation' => '2026-06-25 12:00:00',
        'source_type' => $source->value,
    ]);
}

/** Escribe un par directamente, sin pasar por el repositorio ni por el detector. */
function insertPair(User $user, Transaction $a, Transaction $b, int $masterId, ReconciliationKind $kind): void
{
    DB::table('reconciliation_candidates')->insert([
        'user_id' => $user->id,
        'transaction_id' => $a->id,
        'candidate_transaction_id' => $b->id,
        'master_transaction_id' => $masterId,
        'kind' => $kind->value,
        'status' => ReconciliationStatus::CONFIRMED->value,
        'resolved_by' => ResolvedBy::SYSTEM->value,
        'resolved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('rechaza que dos pares cruzados se cuelguen del mismo asiento', function () {
    $user = User::factory()->create();

    $asiento = constraintMovement($user, SourceType::IMPORT_STATEMENT);
    $unPago = constraintMovement($user, SourceType::IMPORT_APP);
    $otroPago = constraintMovement($user, SourceType::IMPORT_APP);

    insertPair($user, $unPago, $asiento, $asiento->id, ReconciliationKind::CROSS_SOURCE);

    // Este es el defecto que se vio en produccion, ahora imposible de escribir.
    expect(fn () => insertPair($user, $otroPago, $asiento, $asiento->id, ReconciliationKind::CROSS_SOURCE))
        ->toThrow(QueryException::class);
});

it('deja que un grupo de la misma fuente colapse sobre un solo sobreviviente', function () {
    $user = User::factory()->create();

    // La contracara, y es la razon por la que el indice es PARCIAL en vez de un
    // unique sobre `matched_transaction_id`: `ReconcileImportDuplicates` junta tres
    // copias del mismo movimiento traidas por la misma puerta, y ahi un maestro con
    // dos satelites es lo correcto. Un indice sin `kind` romperia ese comando.
    $sobreviviente = constraintMovement($user, SourceType::IMPORT_APP);
    $copia = constraintMovement($user, SourceType::IMPORT_APP);
    $otraCopia = constraintMovement($user, SourceType::IMPORT_APP);

    insertPair($user, $sobreviviente, $copia, $sobreviviente->id, ReconciliationKind::SAME_SOURCE);
    insertPair($user, $sobreviviente, $otraCopia, $sobreviviente->id, ReconciliationKind::SAME_SOURCE);

    expect(DB::table('reconciliation_candidates')->where('master_transaction_id', $sobreviviente->id)->count())
        ->toBe(2);
});

it('no reserva el asiento mientras el par sigue siendo una pregunta', function () {
    $user = User::factory()->create();

    $asiento = constraintMovement($user, SourceType::IMPORT_STATEMENT);
    $unPago = constraintMovement($user, SourceType::IMPORT_APP);

    // Un par pendiente no descuenta nada, asi que no tiene maestro y no puede
    // bloquear al asiento. El indice es parcial tambien en `status` por esto.
    DB::table('reconciliation_candidates')->insert([
        'user_id' => $user->id,
        'transaction_id' => $unPago->id,
        'candidate_transaction_id' => $asiento->id,
        'master_transaction_id' => null,
        'kind' => ReconciliationKind::CROSS_SOURCE->value,
        'status' => ReconciliationStatus::PENDING->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otroPago = constraintMovement($user, SourceType::IMPORT_APP);

    expect(fn () => insertPair($user, $otroPago, $asiento, $asiento->id, ReconciliationKind::CROSS_SOURCE))
        ->not->toThrow(QueryException::class);
});

it('exige que el maestro exista exactamente cuando el par esta confirmado', function () {
    $user = User::factory()->create();

    $asiento = constraintMovement($user, SourceType::IMPORT_STATEMENT);
    $pago = constraintMovement($user, SourceType::IMPORT_APP);

    // Un confirmado sin maestro es una unificacion sin nada que la explique; un
    // pendiente CON maestro es un movimiento descontado antes de que nadie decida.
    // Los dos son la misma incoherencia y la base rechaza los dos.
    expect(fn () => DB::table('reconciliation_candidates')->insert([
        'user_id' => $user->id,
        'transaction_id' => $pago->id,
        'candidate_transaction_id' => $asiento->id,
        'master_transaction_id' => null,
        'kind' => ReconciliationKind::CROSS_SOURCE->value,
        'status' => ReconciliationStatus::CONFIRMED->value,
        'resolved_by' => ResolvedBy::SYSTEM->value,
        'resolved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('exige que el maestro sea uno de los dos lados del par', function () {
    $user = User::factory()->create();

    $asiento = constraintMovement($user, SourceType::IMPORT_STATEMENT);
    $pago = constraintMovement($user, SourceType::IMPORT_APP);
    $ajeno = constraintMovement($user, SourceType::MANUAL);

    // Sin esto la columna acepta cualquier transaccion del sistema y el par deja de
    // significar algo: se descontaria una fila que nadie comparo con nadie.
    expect(fn () => insertPair($user, $pago, $asiento, $ajeno->id, ReconciliationKind::CROSS_SOURCE))
        ->toThrow(QueryException::class);
});
