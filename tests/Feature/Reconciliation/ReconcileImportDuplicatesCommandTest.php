<?php

declare(strict_types=1);

use App\Actions\Reconciliation\ResolveReconciliationCandidateAction;
use App\Enums\ReconciliationKind;
use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\Detail;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Escribe una fila de importacion tal cual la dejo el importador con el defecto:
 * el mismo movimiento repetido, a veces con el mensaje en null y a veces en
 * cadena vacia.
 */
function importedRow(
    User $user,
    Detail $detail,
    string $amount,
    string $at,
    ?string $message = null,
    string $type = 'expense'
): Transaction {
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => $amount,
        'type_transaction' => $type,
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

it('nunca fusiona un ingreso con un gasto', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    // Mismo monto, mismo comercio, mismo instante, sentidos opuestos. Colapsarlos
    // haria desaparecer plata que entro y plata que salio a la vez.
    $gasto = importedRow($user, $detail, '100.00', '2026-07-08 14:52:00');
    $ingreso = importedRow($user, $detail, '100.00', '2026-07-08 14:52:00', null, 'income');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($gasto->fresh()->matched_transaction_id)->toBeNull()
        ->and($ingreso->fresh()->matched_transaction_id)->toBeNull();
});

it('informa el gasto y el ingreso por separado, nunca sumados', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    importedRow($user, $detail, '200.00', '2026-07-08 14:52:00');
    importedRow($user, $detail, '200.00', '2026-07-08 14:52:00');

    $otro = merchant($user, 'SUELDO');
    importedRow($user, $otro, '50.00', '2026-07-09 10:00:00', null, 'income');
    importedRow($user, $otro, '50.00', '2026-07-09 10:00:00', null, 'income');

    // Se captura la salida entera en vez de encadenar `expectsOutputToContain`:
    // cada expectativa de esas consume UNA linea, y aca las dos afirmaciones que
    // importan -- la etiqueta y su monto -- viven en la misma.
    Artisan::call('transactions:reconcile-import-duplicates');
    $output = Artisan::output();

    // Una sola cifra de S/ 250 no describiria ninguna magnitud real.
    expect($output)->toContain('Gasto')
        ->and($output)->toContain('S/ 200.00')
        ->and($output)->toContain('Ingreso')
        ->and($output)->toContain('S/ 50.00')
        ->and($output)->not->toContain('S/ 250.00');
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

// ---------------------------------------- reimportes de una copia ya satelite

/**
 * El segundo caso que deja plata contando doble: la copia vieja del Excel ya no
 * es visible porque el extracto la absorbio, y la reimportacion entra visible al
 * lado del extracto. El emparejamiento de arriba solo junta filas visibles, asi
 * que no la ve.
 */
function statementRow(User $user, string $amount, string $at, ?int $categoryId = null): Transaction
{
    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => merchant($user, 'YAPE JOSE TOR '.$at)->id,
        'amount' => $amount,
        'type_transaction' => 'expense',
        'date_operation' => $at,
        'source_type' => SourceType::IMPORT_STATEMENT->value,
        'category_id' => $categoryId,
        'is_manual' => false,
    ]);
}

function absorbedBy(Transaction $satellite, Transaction $master): Transaction
{
    $satellite->update(['matched_transaction_id' => $master->id]);

    return $satellite;
}

it('une la reimportacion al maestro raiz cuando su gemela ya es satelite', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    $vieja = absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    // Directo a la raiz y no a la gemela: un satelite apuntando a un satelite
    // es una cadena, y las cadenas son lo que este comando evita.
    expect($reimportada->fresh()->matched_transaction_id)->toBe($extracto->id)
        ->and($vieja->fresh()->matched_transaction_id)->toBe($extracto->id)
        ->and($extracto->fresh()->matched_transaction_id)->toBeNull();
});

it('sigue la cadena hasta el maestro de arriba', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    $intermedia = absorbedBy(importedRow($user, merchant($user, 'OTRO'), '40.00', '2026-07-10 10:00:00'), $extracto);
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $intermedia);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:30');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($reimportada->fresh()->matched_transaction_id)->toBe($extracto->id);
});

it('registra el par contra la gemela como misma fuente, resuelto por el sistema', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    $vieja = absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    // El par es Yape contra Yape. Registrarlo contra el extracto lo volveria un
    // segundo cruce sobre el mismo asiento, que la base prohibe con razon.
    $candidate = ReconciliationCandidate::sole();

    expect($candidate->transaction_id)->toBe($vieja->id)
        ->and($candidate->candidate_transaction_id)->toBe($reimportada->id)
        ->and($candidate->master_transaction_id)->toBe($vieja->id)
        ->and($candidate->kind)->toBe(ReconciliationKind::SAME_SOURCE)
        ->and($candidate->status)->toBe(ReconciliationStatus::CONFIRMED)
        ->and($candidate->resolved_by)->toBe(ResolvedBy::SYSTEM);
});

it('se deshace desde la lista de unificados y la reimportacion vuelve a contar', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    $vieja = absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    app(ResolveReconciliationCandidateAction::class)->undo(ReconciliationCandidate::sole());

    expect($reimportada->fresh()->matched_transaction_id)->toBeNull()
        ->and($vieja->fresh()->matched_transaction_id)->toBe($extracto->id);
});

it('pasa al maestro la categoria de la reimportacion cuando no tiene ninguna', function () {
    $user = User::factory()->create();
    $detail = merchant($user);
    $comida = Category::factory()->create(['user_id' => $user->id]);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');
    $reimportada->update(['category_id' => $comida->id]);

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($extracto->fresh()->category_id)->toBe($comida->id);
});

it('no pisa la categoria que el maestro ya tiene', function () {
    $user = User::factory()->create();
    $detail = merchant($user);
    $comida = Category::factory()->create(['user_id' => $user->id]);
    $otra = Category::factory()->create(['user_id' => $user->id]);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00', $comida->id);
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');
    $reimportada->update(['category_id' => $otra->id]);

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($extracto->fresh()->category_id)->toBe($comida->id);
});

it('no une la reimportacion si la gemela satelite tiene otro mensaje o esta a mas de la tolerancia', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00', 'almuerzo'), $extracto);
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-11 08:00:00'), statementRow($user, '40.00', '2026-07-11 00:00:00'));

    $otroMensaje = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00', 'cena');
    $lejos = importedRow($user, $detail, '40.00', '2026-07-11 08:01:01');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($otroMensaje->fresh()->matched_transaction_id)->toBeNull()
        ->and($lejos->fresh()->matched_transaction_id)->toBeNull()
        ->and(ReconciliationCandidate::count())->toBe(0);
});

it('informa la reimportacion sin tocar nada mientras no se pase --apply', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');

    Artisan::call('transactions:reconcile-import-duplicates');
    $output = Artisan::output();

    expect($output)->toContain('S/ 40.00')
        ->and($reimportada->fresh()->matched_transaction_id)->toBeNull()
        ->and(ReconciliationCandidate::count())->toBe(0);
});

it('no vuelve a unir una reimportacion que el usuario ya separo de su gemela', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    $vieja = absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');

    ReconciliationCandidate::create([
        'user_id' => $user->id,
        'transaction_id' => $vieja->id,
        'candidate_transaction_id' => $reimportada->id,
        'status' => ReconciliationStatus::REJECTED,
        'resolved_by' => ResolvedBy::USER,
        'resolved_at' => now(),
    ]);

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($reimportada->fresh()->matched_transaction_id)->toBeNull();
});

it('une al maestro raiz las reimportaciones repetidas sin dejar cadenas', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $una = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');
    $otra = importedRow($user, $detail, '40.00', '2026-07-10 19:59:10');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($una->fresh()->matched_transaction_id)->toBe($extracto->id)
        ->and($otra->fresh()->matched_transaction_id)->toBe($extracto->id);
});

it('es idempotente con las reimportaciones', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();
    $this->artisan('transactions:reconcile-import-duplicates --apply')
        ->expectsOutputToContain('No hay duplicados')
        ->assertSuccessful();

    expect(ReconciliationCandidate::count())->toBe(1)
        ->and($reimportada->fresh()->matched_transaction_id)->toBe($extracto->id);
});

it('no une la reimportacion a un maestro del que el usuario ya la separo', function () {
    $user = User::factory()->create();
    $detail = merchant($user);

    $extracto = statementRow($user, '40.00', '2026-07-10 00:00:00');
    absorbedBy(importedRow($user, $detail, '40.00', '2026-07-10 19:59:00'), $extracto);
    $reimportada = importedRow($user, $detail, '40.00', '2026-07-10 19:59:00');

    // El detector cruzado ya pregunto por este par y el usuario dijo que no.
    ReconciliationCandidate::create([
        'user_id' => $user->id,
        'transaction_id' => $extracto->id,
        'candidate_transaction_id' => $reimportada->id,
        'status' => ReconciliationStatus::REJECTED,
        'resolved_by' => ResolvedBy::USER,
        'resolved_at' => now(),
    ]);

    $this->artisan('transactions:reconcile-import-duplicates --apply')->assertSuccessful();

    expect($reimportada->fresh()->matched_transaction_id)->toBeNull()
        ->and(ReconciliationCandidate::count())->toBe(1);
});
