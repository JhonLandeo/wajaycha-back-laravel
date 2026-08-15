<?php

declare(strict_types=1);

use App\Actions\Reconciliation\ResolveReconciliationCandidateAction;
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

uses(RefreshDatabase::class);

/**
 * Escribe una transaccion sin pasar por ningun importador: lo que se prueba aca es
 * la regla de cruce, no como llego la fila.
 */
function movement(
    User $user,
    SourceType $source,
    string $amount,
    string $at,
    string $description = 'BODEGA',
    bool $estimatedDate = false
): Transaction {
    // `details` es unico por (user_id, description): dos movimientos del mismo
    // comercio comparten fila, igual que en produccion.
    $detail = Detail::query()->firstWhere([
        'user_id' => $user->id,
        'description' => $description,
    ]) ?? Detail::factory()->create([
        'user_id' => $user->id,
        'description' => $description,
    ]);

    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => $amount,
        'type_transaction' => 'expense',
        'date_operation' => $at,
        'is_date_estimated' => $estimatedDate,
        'source_type' => $source->value,
        'is_manual' => $source === SourceType::CAPTURE,
    ]);
}

function detector(): DuplicateCandidateDetector
{
    return app(DuplicateCandidateDetector::class);
}

function resolver(): ResolveReconciliationCandidateAction
{
    return app(ResolveReconciliationCandidateAction::class);
}

/**
 * Un movimiento con procedencia institucional: o salio de una billetera, o es el
 * asiento de un banco. `movement()` de arriba no las distingue porque las reglas
 * por cercania no las miran; la regla estructural no mira otra cosa.
 */
function reconMovement(
    User $user,
    SourceType $source,
    string $amount,
    string $at,
    string $type = 'expense',
    ?PaymentService $wallet = null,
    ?FinancialEntity $ledger = null,
    ?Detail $detail = null
): Transaction {
    $detail ??= Detail::factory()->create(['user_id' => $user->id]);

    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => $amount,
        'type_transaction' => $type,
        'date_operation' => $at,
        'source_type' => $source->value,
        'payment_service_id' => $wallet?->id,
        'financial_entity_id' => $ledger?->id ?? $wallet?->financial_entity_id,
    ]);
}

// Separaciones tomadas de los datos reales: los cuatro duplicados verdaderos
// estaban a 6, 15, 33 y 59 segundos; el par que NO lo era, a diez horas.
const SECONDS_APART = ['2026-08-10 14:30:00', '2026-08-10 14:30:06'];
const HOURS_APART = ['2026-08-10 02:55:00', '2026-08-10 12:56:00'];

// El extracto del BCP no trae hora del dia, asi que toda fila suya cae a
// medianoche. El pago real ocurrio a las 13:09. Ese hueco de trece horas no mide
// nada: es la hora a la que la persona pago, contra una medianoche inventada.
const STATEMENT_MIDNIGHT = '2026-06-25 00:00:00';
const WALLET_AFTERNOON = '2026-06-25 13:09:59';
const WALLET_EVENING = '2026-06-25 18:02:03';

// ------------------------------------------------------------ decide solo

it('unifica sin preguntar el par separado por segundos', function () {
    $user = User::factory()->create();

    $captura = movement($user, SourceType::CAPTURE, '25.50', SECONDS_APART[0], 'bodega de la esquina');
    $importada = movement($user, SourceType::IMPORT_APP, '25.50', SECONDS_APART[1], 'JUAN PEREZ RODRIGUEZ');

    $record = detector()->inspect($importada);

    expect($record->status)->toBe(ReconciliationStatus::CONFIRMED)
        ->and($record->resolved_by)->toBe(ResolvedBy::SYSTEM)
        // El Excel de Yape manda sobre una foto: la captura deja de contar.
        ->and($captura->fresh()->matched_transaction_id)->toBe($importada->id)
        ->and($importada->fresh()->matched_transaction_id)->toBeNull();
});

it('unifica aunque las descripciones no se parezcan en nada', function () {
    $user = User::factory()->create();

    // Gemini escribe lo que leyo de la foto; Yape registra el nombre del titular.
    // La cercania temporal es la unica senal, y es la que se eligio.
    movement($user, SourceType::CAPTURE, '40.00', SECONDS_APART[0], 'pollería del jirón');
    $importada = movement($user, SourceType::IMPORT_APP, '40.00', SECONDS_APART[1], 'ROSA QUISPE MAMANI');

    expect(detector()->inspect($importada)->status)->toBe(ReconciliationStatus::CONFIRMED);
});

it('nunca unifica sola una fila del mismo origen', function () {
    $user = User::factory()->create();

    // Dos Yapes tuyos del mismo monto seguidos son dos pagos. El cruce es siempre
    // entre puertas distintas, y por eso pagar dos veces lo mismo no se fusiona.
    movement($user, SourceType::IMPORT_APP, '25.00', SECONDS_APART[0], 'PEDRO A');
    $segunda = movement($user, SourceType::IMPORT_APP, '25.00', SECONDS_APART[1], 'LUIS B');

    expect(detector()->inspect($segunda))->toBeNull();
});

// ------------------------------------------------------------ pregunta

it('pregunta por el par de diez horas en vez de unificarlo', function () {
    $user = User::factory()->create();

    // El caso real: mismo monto, mismo dia, dos comercios distintos.
    $taxi = movement($user, SourceType::CAPTURE, '13.00', HOURS_APART[0], 'IZI*TABOADA PILLACA JORGE');
    $menu = movement($user, SourceType::IMPORT_APP, '13.00', HOURS_APART[1], 'Mario May*');

    $record = detector()->inspect($menu);

    expect($record->status)->toBe(ReconciliationStatus::PENDING)
        ->and($record->resolved_by)->toBeNull()
        // Los dos siguen sumando: la sospecha no toca los totales.
        ->and($taxi->fresh()->matched_transaction_id)->toBeNull()
        ->and($menu->fresh()->matched_transaction_id)->toBeNull();
});

it('pregunta cuando la fecha de la captura es un relleno, por cerca que caiga', function () {
    $user = User::factory()->create();

    // Gemini no leyo la fecha del comprobante: `date_operation` es la hora en que
    // se mando la foto. Seis segundos de diferencia contra un relleno no dicen
    // nada sobre cuando se movio la plata.
    movement($user, SourceType::CAPTURE, '25.50', SECONDS_APART[0], 'bodega', estimatedDate: true);
    $importada = movement($user, SourceType::IMPORT_APP, '25.50', SECONDS_APART[1], 'JUAN PEREZ');

    expect(detector()->inspect($importada)->status)->toBe(ReconciliationStatus::PENDING);
});

it('no cruza movimientos separados por mas de la ventana', function () {
    $user = User::factory()->create();

    movement($user, SourceType::CAPTURE, '25.50', '2026-08-07 14:30:00');
    $importada = movement($user, SourceType::IMPORT_APP, '25.50', '2026-08-10 14:30:00');

    expect(detector()->inspect($importada))->toBeNull();
});

it('todavia pregunta por un par a mas de un dia de distancia', function () {
    $user = User::factory()->create();

    // Seis de los 3010 pares conciliados de wajaycha_audit pasan las 24 horas, el
    // mas ancho a 45. Con la ventana en 24 no quedaban sin unificar: quedaban
    // invisibles.
    movement($user, SourceType::CAPTURE, '25.50', '2026-08-08 20:00:00');
    $importada = movement($user, SourceType::IMPORT_APP, '25.50', '2026-08-10 14:30:00');

    expect(detector()->inspect($importada)->status)->toBe(ReconciliationStatus::PENDING);
});

// --------------------------------------------------- el mismo movimiento por construccion

/** Yape la emite el BCP, que es exactamente lo que dice `payment_services`. */
function bcpConYape(): array
{
    $bcp = FinancialEntity::factory()->create(['name' => 'Banco de Crédito del Perú']);

    $yape = PaymentService::create([
        'name' => 'Yape',
        'financial_entity_id' => $bcp->id,
        'type' => 'Billetera Digital',
    ]);

    return [$bcp, $yape];
}

it('unifica sola la billetera contra el extracto de su emisor, sin mirar la hora', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = bcpConYape();

    // Trece horas de diferencia, y aun asi no hay nada que evaluar: una billetera
    // descontada de esa tarjeta no produce un movimiento PARECIDO al del banco,
    // produce el mismo. La hora del extracto es medianoche porque el PDF no trae
    // hora, no porque el banco haya asentado a medianoche.
    $pago = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_AFTERNOON, 'expense', wallet: $yape);
    $asiento = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'expense', ledger: $bcp);

    $record = detector()->inspect($asiento);

    expect($record->status)->toBe(ReconciliationStatus::CONFIRMED)
        ->and($record->resolved_by)->toBe(ResolvedBy::SYSTEM)
        ->and($pago->fresh()->matched_transaction_id)->toBe($asiento->id);
});

it('aparea de a dos un dia con dos pagos iguales, sin preguntar nada', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = bcpConYape();

    // ESTE es el caso que hacia inservible la lista. Dos ingresos de S/ 5 el mismo
    // dia y dos filas del extracto: son indistinguibles entre si, asi que "¿cual va
    // con cual?" no tiene respuesta y cualquier asignacion da el mismo total. Sobre
    // los datos reales esa pregunta se contesto "son distintos" y los cuatro
    // movimientos siguieron contando.
    $primero = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_AFTERNOON, 'income', wallet: $yape);
    $segundo = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_EVENING, 'income', wallet: $yape);

    $unoDelBanco = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'income', ledger: $bcp);
    $otroDelBanco = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'income', ledger: $bcp);

    detector()->inspect($unoDelBanco);
    detector()->inspect($otroDelBanco);

    // Los dos de la billetera dejaron de contar y no quedo una sola pregunta abierta.
    expect($primero->fresh()->matched_transaction_id)->not->toBeNull()
        ->and($segundo->fresh()->matched_transaction_id)->not->toBeNull()
        ->and(ReconciliationCandidate::where('status', ReconciliationStatus::PENDING)->count())->toBe(0);
});

it('no deja que un asiento del banco absorba dos pagos', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = bcpConYape();

    // La direccion importa y por eso este test entra por la billetera. Inspeccionando
    // desde el extracto el defecto no aparece: cada pago que se aparea queda con
    // `matched_transaction_id` puesto y el siguiente ya no lo ve. Pero ese campo lo
    // lleva el SATELITE, asi que el asiento maestro se queda con el suyo en null y
    // seguia figurando como libre para siempre. En produccion los dos pagos del dia
    // se colgaron del mismo asiento y el otro asiento quedo contando solo.
    $unPago = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_AFTERNOON, 'income', wallet: $yape);
    $otroPago = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_EVENING, 'income', wallet: $yape);

    $unAsiento = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'income', ledger: $bcp);
    $otroAsiento = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'income', ledger: $bcp);

    detector()->inspect($unPago);
    detector()->inspect($otroPago);

    $maestros = Transaction::whereIn('id', [$unPago->id, $otroPago->id])
        ->pluck('matched_transaction_id')
        ->all();

    // Cada pago contra un asiento distinto, y ningun asiento contando de mas.
    expect(array_unique($maestros))->toHaveCount(2)
        ->and($maestros)->not->toContain(null)
        ->and(Transaction::whereIn('id', [$unAsiento->id, $otroAsiento->id])
            ->whereNull('matched_transaction_id')->count())->toBe(2);
});

it('aparea cada pago con el asiento de su mismo comercio', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = bcpConYape();

    // El extracto del BCP nombra a la contraparte igual que el Excel, y Entity
    // Resolution ya dejo a las dos filas colgando del mismo `detail`. Ignorarlo hacia
    // que el cobro de Yudita quedara explicado por el pago a Andi Cuy — el total
    // daba bien y la pantalla mostraba una mentira.
    $yudita = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Yape Yudita Qui']);
    $andi = Detail::factory()->create(['user_id' => $user->id, 'description' => 'Yape Andi Cuy']);

    // El asiento de Yudita se crea PRIMERO para que el orden por `id` a secas eligiera
    // el equivocado: sin la preferencia por comercio este test falla.
    $asientoYudita = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'income', ledger: $bcp, detail: $yudita);
    $asientoAndi = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'income', ledger: $bcp, detail: $andi);

    $pagoAndi = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_AFTERNOON, 'income', wallet: $yape, detail: $andi);
    $pagoYudita = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_EVENING, 'income', wallet: $yape, detail: $yudita);

    detector()->inspect($pagoAndi);
    detector()->inspect($pagoYudita);

    expect($pagoAndi->fresh()->matched_transaction_id)->toBe($asientoAndi->id)
        ->and($pagoYudita->fresh()->matched_transaction_id)->toBe($asientoYudita->id);
});

it('deja contando lo que sobra del dia y no lo pregunta', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = bcpConYape();

    // Un pago hecho con SALDO de la billetera nunca toca la tarjeta, asi que nunca
    // aparece en el extracto. Con el extracto del mes ya cargado, esa ausencia no es
    // una duda: es la respuesta. Preguntarla seria pedir que confirmen un negativo.
    $conSaldo = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_AFTERNOON, 'expense', wallet: $yape);
    $conTarjeta = reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_EVENING, 'expense', wallet: $yape);

    $unico = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'expense', ledger: $bcp);

    detector()->inspect($unico);

    $sobrevivientes = Transaction::whereIn('id', [$conSaldo->id, $conTarjeta->id])
        ->whereNull('matched_transaction_id')
        ->count();

    expect($sobrevivientes)->toBe(1)
        ->and(ReconciliationCandidate::where('status', ReconciliationStatus::PENDING)->count())->toBe(0);
});

it('no da por estructural una billetera de otra institucion', function () {
    $user = User::factory()->create();
    [, $yape] = bcpConYape();

    // Plin no lo emite el BCP. Que caiga el mismo dia y por el mismo monto que un
    // cargo del BCP no lo convierte en el mismo movimiento — ahi si hay algo que
    // preguntar, y se pregunta.
    $otroBanco = FinancialEntity::factory()->create(['name' => 'Interbank']);

    reconMovement($user, SourceType::IMPORT_APP, '5.00', WALLET_AFTERNOON, 'expense', wallet: $yape);
    $ajeno = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'expense', ledger: $otroBanco);

    expect(detector()->inspect($ajeno)->status)->toBe(ReconciliationStatus::PENDING);
});

it('no cruza el limite del dia como si fuera estructural', function () {
    $user = User::factory()->create();
    [$bcp, $yape] = bcpConYape();

    // Un minuto antes de medianoche es otro dia calendario. El extracto dice en que
    // DIA ocurrio y no hay nada mas fino que eso, asi que la regla estructural se
    // detiene ahi y el par vuelve a decidirse por la via medida.
    reconMovement($user, SourceType::IMPORT_APP, '5.00', '2026-06-24 23:59:00', 'expense', wallet: $yape);
    $asiento = reconMovement($user, SourceType::IMPORT_STATEMENT, '5.00', STATEMENT_MIDNIGHT, 'expense', ledger: $bcp);

    // Sigue unificandose porque esta solo en la ventana, pero por la otra regla.
    expect(detector()->inspect($asiento)->status)->toBe(ReconciliationStatus::CONFIRMED);
});

it('unifica sola la fila del banco contra la app cuando el candidato esta solo', function () {
    $user = User::factory()->create();

    // Para este par la distancia no decide: el estado de cuenta registra cuando el
    // banco asiento, no cuando pagaste, y en wajaycha_audit el hueco va de 0 a 45
    // horas. Lo que decide es la soledad. De los 3010 pares conciliados, 2396
    // tienen un unico candidato de ese monto en la ventana y en los 2396 el mas
    // cercano es el correcto, sin excepciones. Diez horas de diferencia no debilitan
    // eso: es el par normal, no el sospechoso.
    $yape = movement($user, SourceType::IMPORT_APP, '25.50', HOURS_APART[0], 'YAPE');
    $estado = movement($user, SourceType::IMPORT_STATEMENT, '25.50', HOURS_APART[1], 'BANCO');

    $record = detector()->inspect($estado);

    expect($record->status)->toBe(ReconciliationStatus::CONFIRMED)
        ->and($record->resolved_by)->toBe(ResolvedBy::SYSTEM)
        // El extracto es el libro por el que la plata paso de verdad: manda sobre
        // el Excel, y el que deja de contar es el de la app.
        ->and($yape->fresh()->matched_transaction_id)->toBe($estado->id)
        ->and($estado->fresh()->matched_transaction_id)->toBeNull();
});

it('pregunta por la fila del banco cuando hay mas de un candidato del mismo monto', function () {
    $user = User::factory()->create();

    // Dos movimientos de S/ 25,50 en la ventana y ninguna manera de saber cual es
    // cual: sobre los 614 pares ambiguos de wajaycha_audit el mas cercano acierta
    // 421 veces, asi que unificar solo mal-emparejaria 193 movimientos. Preguntar
    // no es timidez, es la unica lectura honesta de la evidencia.
    movement($user, SourceType::IMPORT_APP, '25.50', HOURS_APART[0], 'YAPE');
    movement($user, SourceType::IMPORT_APP, '25.50', SECONDS_APART[0], 'OTRO YAPE');
    $estado = movement($user, SourceType::IMPORT_STATEMENT, '25.50', HOURS_APART[1], 'BANCO');

    $record = detector()->inspect($estado);

    expect($record->status)->toBe(ReconciliationStatus::PENDING)
        // Y mientras la pregunta esta abierta, los dos siguen sumando. Un total
        // inflado que la interfaz marca es honesto; uno faltante no.
        ->and($estado->fresh()->matched_transaction_id)->toBeNull();
});

it('tampoco unifica sola una combinacion que nadie midio', function () {
    $user = User::factory()->create();

    // `yape_matched` no esta en la tabla de umbrales. Una fuente sin
    // comportamiento medido se pregunta: la lectura segura de "no hay evidencia"
    // no es unificar.
    movement($user, SourceType::CAPTURE, '25.50', SECONDS_APART[0], 'BODEGA');
    $rara = movement($user, SourceType::YAPE_MATCHED, '25.50', SECONDS_APART[1], 'OTRA');

    expect(detector()->inspect($rara)->status)->toBe(ReconciliationStatus::PENDING);
});

it('no depende de cual de los dos lados llego ultimo', function () {
    $user = User::factory()->create();

    // Cual llego primero es un accidente de cuando el usuario exporto su Excel, y
    // no puede cambiar lo que el sistema esta dispuesto a decidir.
    $captura = movement($user, SourceType::CAPTURE, '30.00', SECONDS_APART[1], 'BODEGA');
    movement($user, SourceType::IMPORT_APP, '30.00', SECONDS_APART[0], 'YAPE');

    expect(detector()->inspect($captura)->status)->toBe(ReconciliationStatus::CONFIRMED);
});

it('no cruza montos distintos, tipos distintos ni usuarios distintos', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    movement($user, SourceType::CAPTURE, '25.51', SECONDS_APART[0]);
    movement($otro, SourceType::CAPTURE, '25.50', SECONDS_APART[0]);

    $ingreso = movement($user, SourceType::CAPTURE, '25.50', SECONDS_APART[0], 'OTRO');
    $ingreso->update(['type_transaction' => 'income']);

    $importada = movement($user, SourceType::IMPORT_APP, '25.50', SECONDS_APART[1]);

    expect(detector()->inspect($importada))->toBeNull();
});

it('propone un solo candidato: el mas cercano en el tiempo', function () {
    $user = User::factory()->create();

    // El cafe de todos los dias: el mismo monto repetido dentro de la ventana.
    movement($user, SourceType::CAPTURE, '12.00', '2026-08-10 08:00:00', 'CAFE A');
    $cercana = movement($user, SourceType::CAPTURE, '12.00', '2026-08-10 13:50:00', 'CAFE B');

    $importada = movement($user, SourceType::IMPORT_APP, '12.00', '2026-08-10 14:00:00', 'CAFE C');

    $record = detector()->inspect($importada);

    expect($record->candidate_transaction_id)->toBe($cercana->id)
        ->and(ReconciliationCandidate::count())->toBe(1);
});

it('no vuelve a preguntar por un par que el usuario ya descarto', function () {
    $user = User::factory()->create();

    $captura = movement($user, SourceType::CAPTURE, '13.00', HOURS_APART[0], 'TAXI');
    $importada = movement($user, SourceType::IMPORT_APP, '13.00', HOURS_APART[1], 'MENU');

    resolver()->reject(detector()->inspect($importada));

    // Reimportar el mismo Excel no puede resucitar una pregunta ya respondida.
    expect(detector()->inspect($importada))->toBeNull()
        ->and(detector()->inspect($captura))->toBeNull();
});

it('no deja una fila metida en dos pares abiertos a la vez', function () {
    $user = User::factory()->create();

    // Confirmar los dos pares dejaria a la fila del medio siendo satelite de uno y
    // maestro del otro. `fn_get_transactions` solo mira `matched_transaction_id IS
    // NULL`, asi que descontaria al del medio y el del extremo desapareceria de
    // los totales sin que nadie lo pida.
    $captura = movement($user, SourceType::CAPTURE, '13.00', HOURS_APART[0], 'TAXI');
    $primera = movement($user, SourceType::IMPORT_APP, '13.00', HOURS_APART[1], 'MENU');

    $abierto = detector()->inspect($primera);
    expect($abierto->status)->toBe(ReconciliationStatus::PENDING);

    $segunda = movement($user, SourceType::IMPORT_STATEMENT, '13.00', '2026-08-10 13:10:00', 'BANCO');

    // `segunda` cae a 14 minutos de `primera`, que ya tiene una pregunta abierta,
    // y a diez horas de `captura`, que tambien.
    expect(detector()->inspect($segunda))->toBeNull()
        ->and(detector()->inspect($primera))->toBeNull()
        ->and(ReconciliationCandidate::where('user_id', $user->id)->count())->toBe(1)
        ->and($captura->fresh()->matched_transaction_id)->toBeNull();
});

// ------------------------------------------------------------ decisiones

it('al confirmar conserva la captura y deja de contarla', function () {
    $user = User::factory()->create();

    $captura = movement($user, SourceType::CAPTURE, '13.00', HOURS_APART[0], 'TAXI');
    $importada = movement($user, SourceType::IMPORT_APP, '13.00', HOURS_APART[1], 'MENU');

    $record = resolver()->confirm(detector()->inspect($importada));

    // La foto no se borra: es la evidencia de por que el usuario confia en el numero.
    expect(Transaction::whereKey($captura->id)->exists())->toBeTrue()
        ->and($captura->fresh()->matched_transaction_id)->toBe($importada->id)
        ->and($record->resolved_by)->toBe(ResolvedBy::USER);
});

it('elige por procedencia y no por orden de llegada', function () {
    $user = User::factory()->create();

    // La captura ocurre en la caja; el estado de cuenta llega semanas despues.
    $captura = movement($user, SourceType::CAPTURE, '13.00', HOURS_APART[0], 'TAXI');
    $estado = movement($user, SourceType::IMPORT_STATEMENT, '13.00', HOURS_APART[1], 'BANCO');

    resolver()->confirm(detector()->inspect($estado));

    expect($captura->fresh()->matched_transaction_id)->toBe($estado->id)
        ->and($estado->fresh()->matched_transaction_id)->toBeNull();
});

it('rechaza resolver dos veces la misma sospecha', function () {
    $user = User::factory()->create();

    movement($user, SourceType::CAPTURE, '13.00', HOURS_APART[0], 'TAXI');
    $importada = movement($user, SourceType::IMPORT_APP, '13.00', HOURS_APART[1], 'MENU');

    $record = detector()->inspect($importada);
    resolver()->reject($record);

    expect(fn () => resolver()->confirm($record->fresh()))->toThrow(RuntimeException::class);
});

// ------------------------------------------------------------ deshacer

it('deshacer devuelve los dos movimientos a los totales', function () {
    $user = User::factory()->create();

    $captura = movement($user, SourceType::CAPTURE, '25.50', SECONDS_APART[0], 'bodega');
    $importada = movement($user, SourceType::IMPORT_APP, '25.50', SECONDS_APART[1], 'JUAN PEREZ');

    $record = detector()->inspect($importada);
    $deshecho = resolver()->undo($record);

    expect($captura->fresh()->matched_transaction_id)->toBeNull()
        ->and($importada->fresh()->matched_transaction_id)->toBeNull()
        // Queda como descartado, no como pendiente: el usuario ya respondio, y esa
        // permanencia es lo que impide que el sistema lo vuelva a unificar.
        ->and($deshecho->status)->toBe(ReconciliationStatus::REJECTED)
        ->and($deshecho->resolved_by)->toBe(ResolvedBy::USER);
});

it('no vuelve a unificar sola una pareja que el usuario ya separo', function () {
    $user = User::factory()->create();

    $captura = movement($user, SourceType::CAPTURE, '25.50', SECONDS_APART[0], 'bodega');
    $importada = movement($user, SourceType::IMPORT_APP, '25.50', SECONDS_APART[1], 'JUAN PEREZ');

    resolver()->undo(detector()->inspect($importada));

    expect(detector()->inspect($importada))->toBeNull()
        ->and(detector()->inspect($captura))->toBeNull()
        ->and($captura->fresh()->matched_transaction_id)->toBeNull();
});

it('no deja deshacer lo que decidio el propio usuario', function () {
    $user = User::factory()->create();

    movement($user, SourceType::CAPTURE, '13.00', HOURS_APART[0], 'TAXI');
    $importada = movement($user, SourceType::IMPORT_APP, '13.00', HOURS_APART[1], 'MENU');

    $record = resolver()->confirm(detector()->inspect($importada));

    // Deshacer existe para corregir al sistema. Revertir la propia decision es
    // otra operacion, con otra pregunta detras.
    expect(fn () => resolver()->undo($record))->toThrow(RuntimeException::class);
});
