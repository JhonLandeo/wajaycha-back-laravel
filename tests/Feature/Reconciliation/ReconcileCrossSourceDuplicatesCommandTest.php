<?php

declare(strict_types=1);

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Models\Detail;
use App\Models\FinancialEntity;
use App\Models\PaymentService;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reconciliation\DuplicateCandidateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Una fila que ya existia antes de que el detector la mirara: se escribe directo,
 * sin pasar por ningun importador, que es exactamente el estado del historico.
 */
function legacyRow(
    User $user,
    SourceType $source,
    string $amount,
    string $at,
    string $type = 'expense',
    ?PaymentService $wallet = null,
    ?FinancialEntity $ledger = null
): Transaction {
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => Detail::factory()->create(['user_id' => $user->id])->id,
        'amount' => $amount,
        'type_transaction' => $type,
        'date_operation' => $at,
        'source_type' => $source->value,
        'payment_service_id' => $wallet?->id,
        'financial_entity_id' => $ledger?->id ?? $wallet?->financial_entity_id,
    ]);
}

/** Yape la emite el BCP, igual que en `payment_services` de produccion. */
function legacyBcpAndYape(): array
{
    $bcp = FinancialEntity::factory()->create(['name' => 'Banco de Crédito del Perú']);

    $yape = PaymentService::create([
        'name' => 'Yape',
        'financial_entity_id' => $bcp->id,
        'type' => 'Billetera Digital',
    ]);

    return [$bcp, $yape];
}

const LEGACY_COMMAND = 'transactions:reconcile-cross-source-duplicates';

it('informa el par sin escribir nada mientras no se pase --apply', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = legacyBcpAndYape();

    $pago = legacyRow($user, SourceType::IMPORT_APP, '5.00', '2026-06-25 13:09:59', wallet: $yape);
    $asiento = legacyRow($user, SourceType::IMPORT_STATEMENT, '5.00', '2026-06-25 00:00:00', ledger: $bcp);

    Artisan::call(LEGACY_COMMAND);
    $output = Artisan::output();

    // El reporte describe el enlace que se haria...
    expect($output)->toContain('Simulación')
        ->and($output)->toContain((string) $pago->id)
        ->and($output)->toContain((string) $asiento->id)
        ->and($output)->toContain('5.00')
        ->and($output)->toContain('import_app')
        ->and($output)->toContain('import_statement')
        // ...y la base queda exactamente como estaba.
        ->and(ReconciliationCandidate::count())->toBe(0)
        ->and($pago->fresh()->matched_transaction_id)->toBeNull()
        ->and($asiento->fresh()->matched_transaction_id)->toBeNull();
});

it('une con --apply un Yape y un extracto del mismo dia que nunca se inspeccionaron', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = legacyBcpAndYape();

    $pago = legacyRow($user, SourceType::IMPORT_APP, '5.00', '2026-06-25 13:09:59', wallet: $yape);
    $asiento = legacyRow($user, SourceType::IMPORT_STATEMENT, '5.00', '2026-06-25 00:00:00', ledger: $bcp);

    $this->artisan(LEGACY_COMMAND.' --apply')->assertSuccessful();

    $record = ReconciliationCandidate::sole();

    expect($pago->fresh()->matched_transaction_id)->toBe($asiento->id)
        ->and($asiento->fresh()->matched_transaction_id)->toBeNull()
        ->and($record->status)->toBe(ReconciliationStatus::CONFIRMED)
        ->and($record->resolved_by)->toBe(ResolvedBy::SYSTEM)
        ->and($record->master_transaction_id)->toBe($asiento->id);
});

it('cuelga del extracto al Yape que ya habia absorbido su captura', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = legacyBcpAndYape();

    // Captura y Excel se unieron en vivo; el extracto es anterior al arreglo que
    // deja absorber a un maestro, asi que quedo suelto y el pago cuenta dos veces.
    $captura = legacyRow($user, SourceType::CAPTURE, '33.90', '2026-09-27 13:09:59');
    $excel = legacyRow($user, SourceType::IMPORT_APP, '33.90', '2026-09-27 13:10:05', wallet: $yape);
    app(DuplicateCandidateDetector::class)->inspect($excel);
    expect($captura->fresh()->matched_transaction_id)->toBe($excel->id);

    $extracto = legacyRow($user, SourceType::IMPORT_STATEMENT, '33.90', '2026-09-27 00:00:00', ledger: $bcp);

    $this->artisan(LEGACY_COMMAND.' --apply')->assertSuccessful();

    $contando = Transaction::whereIn('id', [$captura->id, $excel->id, $extracto->id])
        ->whereNull('matched_transaction_id')
        ->pluck('id')
        ->all();

    expect($contando)->toBe([$extracto->id])
        ->and($excel->fresh()->matched_transaction_id)->toBe($extracto->id)
        ->and($captura->fresh()->matched_transaction_id)->toBe($excel->id)
        ->and(ReconciliationCandidate::where('status', ReconciliationStatus::PENDING)->count())->toBe(0);
});

it('arma la cadena de tres puertas aunque ninguna se haya unido antes', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = legacyBcpAndYape();

    // El extracto se escribe PRIMERO para que el orden por id lo inspeccionara antes
    // que a nadie. Desde el extracto, la captura es la fila mas cercana a esa
    // medianoche y el par captura-extracto nunca se decide solo: quedaria una
    // pregunta abierta y la cadena rota. Por eso se inspecciona de menor a mayor
    // autoridad, en el orden en que esas filas llegan en vivo.
    $extracto = legacyRow($user, SourceType::IMPORT_STATEMENT, '33.90', '2026-09-27 00:00:00', ledger: $bcp);
    $captura = legacyRow($user, SourceType::CAPTURE, '33.90', '2026-09-27 13:09:59');
    $excel = legacyRow($user, SourceType::IMPORT_APP, '33.90', '2026-09-27 13:10:05', wallet: $yape);

    $this->artisan(LEGACY_COMMAND.' --apply')->assertSuccessful();

    expect($captura->fresh()->matched_transaction_id)->toBe($excel->id)
        ->and($excel->fresh()->matched_transaction_id)->toBe($extracto->id)
        ->and($extracto->fresh()->matched_transaction_id)->toBeNull()
        ->and(ReconciliationCandidate::where('status', ReconciliationStatus::PENDING)->count())->toBe(0);
});

it('no cuelga dos Yapes de S/ 5 del mismo dia de un unico asiento', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = legacyBcpAndYape();

    $unPago = legacyRow($user, SourceType::IMPORT_APP, '5.00', '2026-06-25 13:09:59', 'income', wallet: $yape);
    $otroPago = legacyRow($user, SourceType::IMPORT_APP, '5.00', '2026-06-25 18:02:03', 'income', wallet: $yape);
    $asiento = legacyRow($user, SourceType::IMPORT_STATEMENT, '5.00', '2026-06-25 00:00:00', 'income', ledger: $bcp);

    $this->artisan(LEGACY_COMMAND.' --apply')->assertSuccessful();

    // El asiento explica un pago. El otro fue con saldo y sigue contando.
    expect(Transaction::where('matched_transaction_id', $asiento->id)->count())->toBe(1)
        ->and(Transaction::whereIn('id', [$unPago->id, $otroPago->id])->whereNull('matched_transaction_id')->count())->toBe(1)
        ->and($asiento->fresh()->matched_transaction_id)->toBeNull();
});

it('es idempotente: la segunda corrida no une nada nuevo', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = legacyBcpAndYape();

    legacyRow($user, SourceType::IMPORT_APP, '5.00', '2026-06-25 13:09:59', wallet: $yape);
    legacyRow($user, SourceType::IMPORT_STATEMENT, '5.00', '2026-06-25 00:00:00', ledger: $bcp);

    $this->artisan(LEGACY_COMMAND.' --apply')->assertSuccessful();
    $after = ReconciliationCandidate::orderBy('id')->get(['id', 'status'])->toArray();
    $linked = Transaction::whereNotNull('matched_transaction_id')->pluck('id')->all();

    Artisan::call(LEGACY_COMMAND.' --apply');
    $output = Artisan::output();

    expect(ReconciliationCandidate::orderBy('id')->get(['id', 'status'])->toArray())->toBe($after)
        ->and(Transaction::whereNotNull('matched_transaction_id')->pluck('id')->all())->toBe($linked)
        ->and($output)->toContain('0 unificado(s)');
});

it('con --user solo toca los movimientos de ese usuario', function () {
    [$bcp, $yape] = legacyBcpAndYape();
    $elegido = User::factory()->create();
    $otro = User::factory()->create();

    $pagoElegido = legacyRow($elegido, SourceType::IMPORT_APP, '5.00', '2026-06-25 13:09:59', wallet: $yape);
    legacyRow($elegido, SourceType::IMPORT_STATEMENT, '5.00', '2026-06-25 00:00:00', ledger: $bcp);

    $pagoOtro = legacyRow($otro, SourceType::IMPORT_APP, '5.00', '2026-06-25 13:09:59', wallet: $yape);
    legacyRow($otro, SourceType::IMPORT_STATEMENT, '5.00', '2026-06-25 00:00:00', ledger: $bcp);

    $this->artisan(LEGACY_COMMAND." --apply --user={$elegido->id}")->assertSuccessful();

    expect($pagoElegido->fresh()->matched_transaction_id)->not->toBeNull()
        ->and($pagoOtro->fresh()->matched_transaction_id)->toBeNull()
        ->and(ReconciliationCandidate::where('user_id', $otro->id)->count())->toBe(0);
});

it('cuenta por usuario los unificados, las preguntas abiertas y los que no tienen pareja', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = legacyBcpAndYape();

    // Un par estructural (se une solo), una captura contra un extracto (solo se
    // pregunta) y un movimiento sin nadie con quien cruzarse. Cada par se cuenta
    // una vez, del lado que lo abrio: cinco filas son dos pares y una suelta.
    legacyRow($user, SourceType::IMPORT_APP, '5.00', '2026-06-25 13:09:59', wallet: $yape);
    legacyRow($user, SourceType::IMPORT_STATEMENT, '5.00', '2026-06-25 00:00:00', ledger: $bcp);
    legacyRow($user, SourceType::CAPTURE, '80.00', '2026-07-02 10:00:00');
    legacyRow($user, SourceType::IMPORT_STATEMENT, '80.00', '2026-07-02 00:00:00', ledger: $bcp);
    legacyRow($user, SourceType::IMPORT_APP, '12.00', '2026-07-10 09:00:00', wallet: $yape);

    Artisan::call(LEGACY_COMMAND);
    $output = Artisan::output();

    expect($output)->toContain("Usuario {$user->id}: 1 unificado(s), 1 pendiente(s), 1 sin pareja.")
        ->and(ReconciliationCandidate::count())->toBe(0);
});
