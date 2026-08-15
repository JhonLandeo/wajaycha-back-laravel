<?php

declare(strict_types=1);

/**
 * `TransactionYapeImport` sat at 0% coverage. It is the code that turns a Yape
 * movement into money in the ledger, so a mapping defect here writes a wrong
 * amount and nothing downstream can detect it.
 *
 * These exercise `model()` directly rather than going through the queued import.
 * The row array is exactly what Maatwebsite hands the mapper once
 * `HeadingRowFormatter::default('none')` has left the Spanish headers untouched,
 * so driving it directly tests the same contract without an xlsx fixture.
 */

use App\Imports\TransactionYapeImport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Same pinning as YapeImportDispatchTest, and for the same reason: the mapper
    // hardcodes `financial_entity_id = 1` and `payment_service_id = 1`, and
    // PostgreSQL sequences do not roll back with RefreshDatabase.
    DB::table('financial_entities')->insert([
        'id' => 1, 'name' => 'Banco de Crédito del Perú', 'type' => 'Banco', 'code' => 'BCP',
    ]);
    DB::table('payment_services')->insert([
        'id' => 1, 'name' => 'Yape', 'financial_entity_id' => 1, 'type' => 'Billetera Digital',
    ]);
});

/**
 * A well-formed Yape export row. Overrides are merged so each test states only
 * the field it is about.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function yapeRow(array $overrides = []): array
{
    return array_merge([
        'Fecha de operación' => '15/03/2026 14:30:00',
        'Origen' => 'JHON PEREZ',
        'Destino' => 'PANADERIA SAN MARTIN',
        'Monto' => '25.50',
        'Tipo de Transacción' => 'PAGASTE',
        'Mensaje' => 'Pago de pan',
    ], $overrides);
}

// ------------------------------------------------------------- behaviour cover

it('mapea una fila PAGASTE a una transaccion de gasto', function () {
    $user = User::factory()->create();

    $model = (new TransactionYapeImport($user->id))->model(yapeRow());

    expect($model)->toBeInstanceOf(Transaction::class);

    $saved = Transaction::query()->where('user_id', $user->id)->sole();

    expect($saved->type_transaction)->toBe('expense');
    expect((float) $saved->amount)->toBe(25.50);
    expect($saved->source_type)->toBe('import_app');
    expect($saved->is_manual)->toBeFalse();
});

it('toma el destino como contraparte cuando pagaste, no el origen', function () {
    $user = User::factory()->create();

    (new TransactionYapeImport($user->id))->model(yapeRow());

    $saved = Transaction::query()->where('user_id', $user->id)->sole();

    expect($saved->detail->description)->toBe('PANADERIA SAN MARTIN');
});

it('mapea una fila que no es PAGASTE a un ingreso y toma el origen', function () {
    $user = User::factory()->create();

    (new TransactionYapeImport($user->id))->model(yapeRow([
        'Tipo de Transacción' => 'TE PAGARON',
    ]));

    $saved = Transaction::query()->where('user_id', $user->id)->sole();

    expect($saved->type_transaction)->toBe('income');
    expect($saved->detail->description)->toBe('JHON PEREZ');
});

/**
 * Asserts every column the mapper writes, not just the interesting ones.
 * Mutation testing removed `payment_service_id`, `financial_entity_id` and
 * `is_manual` from the insert array one at a time and the suite stayed green —
 * the fields were persisted but never verified, so a mapping that silently
 * dropped one would have shipped.
 */
it('persiste cada columna que el mapper declara', function () {
    $user = User::factory()->create();

    (new TransactionYapeImport($user->id))->model(yapeRow());

    $saved = Transaction::query()->where('user_id', $user->id)->sole();

    expect($saved->message)->toBe('Pago de pan');
    // `date_operation` is a timestamptz column but is not listed in the model's
    // casts, so it comes back as a raw string rather than a Carbon instance.
    // Parsed here instead of asserting the driver's string shape.
    expect(Carbon::parse($saved->date_operation)->format('Y-m-d H:i:s'))
        ->toBe('2026-03-15 14:30:00');
    expect($saved->financial_entity_id)->toBe(1);
    expect($saved->payment_service_id)->toBe(1);
    expect($saved->detail_id)->not->toBeNull();
});

it('conserva los centavos exactos del monto', function () {
    $user = User::factory()->create();

    (new TransactionYapeImport($user->id))->model(yapeRow(['Monto' => '1234.05']));

    // Compared as a string against the numeric(15,2) column. Asserting on a float
    // would pass even if a rounding defect were introduced, which is the failure
    // this product cannot detect on its own.
    expect(Transaction::query()->where('user_id', $user->id)->sole()->amount)
        ->toBe('1234.05');
});

// ------------------------------------------------------------------- guards

it('descarta la fila cuando falta un campo obligatorio', function (string $missing) {
    $user = User::factory()->create();

    $model = (new TransactionYapeImport($user->id))->model(yapeRow([$missing => '']));

    expect($model)->toBeNull();
    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
})->with([
    'Fecha de operación',
    'Origen',
    'Destino',
    'Monto',
    'Tipo de Transacción',
]);

it('descarta un duplicado dentro de la tolerancia de 60 segundos', function () {
    $user = User::factory()->create();
    $import = new TransactionYapeImport($user->id);

    $import->model(yapeRow());

    // 30s later: same message, same counterparty, same amount. The export can
    // repeat a movement across two downloads with a slightly different stamp.
    $second = $import->model(yapeRow(['Fecha de operación' => '15/03/2026 14:30:30']));

    expect($second)->toBeNull();
    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('acepta una operacion identica fuera de la tolerancia', function () {
    $user = User::factory()->create();
    $import = new TransactionYapeImport($user->id);

    $import->model(yapeRow());
    // 61s later — past the window, so this is a genuine second purchase.
    $import->model(yapeRow(['Fecha de operación' => '15/03/2026 14:31:01']));

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('no confunde el duplicado de un usuario con el de otro', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    (new TransactionYapeImport($mine->id))->model(yapeRow());
    (new TransactionYapeImport($theirs->id))->model(yapeRow());

    expect(Transaction::query()->where('user_id', $mine->id)->count())->toBe(1);
    expect(Transaction::query()->where('user_id', $theirs->id)->count())->toBe(1);
});

// ------------------------------------------- reimportar el mismo periodo

/**
 * Los tres casos que dejaron 142 pares duplicados y S/ 4702,40 de mas en
 * produccion. Ninguno lo veia el control anterior, y ninguno lo ve el detector de
 * conciliacion: ese cruza fuentes DISTINTAS y esto es Yape contra Yape.
 */
it('descarta el duplicado cuando el mensaje viene vacio de las dos veces', function () {
    $user = User::factory()->create();
    $import = new TransactionYapeImport($user->id);

    // La celda de mensaje vacia llega como null. `message = NULL` no es falso en
    // SQL, es NULL — asi que dos mensajes vacios identicos jamas se reconocian y
    // el control no existia para ningun movimiento sin nota.
    $import->model(yapeRow(['Mensaje' => null]));
    $second = $import->model(yapeRow([
        'Mensaje' => null,
        'Fecha de operación' => '15/03/2026 14:30:09',
    ]));

    expect($second)->toBeNull()
        ->and(Transaction::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('descarta el duplicado cuando una exportacion trae null y la otra vacio', function () {
    $user = User::factory()->create();
    $import = new TransactionYapeImport($user->id);

    // El mismo movimiento reexportado meses despues: el archivo nuevo escribe ''
    // donde el viejo dejaba null. 36 de los 142 pares reales son esto.
    $import->model(yapeRow(['Mensaje' => null]));
    $second = $import->model(yapeRow(['Mensaje' => '']));

    expect($second)->toBeNull()
        ->and(Transaction::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('descarta el duplicado aunque el Excel nombre distinto al mismo comercio', function () {
    $user = User::factory()->create();
    $import = new TransactionYapeImport($user->id);

    $import->model(yapeRow(['Destino' => 'PANADERIA SAN MARTIN']));

    // Entity Resolution cuelga las dos filas del mismo Detail, pero el control
    // buscaba un Detail llamado como el texto crudo del archivo. Al no encontrarlo
    // insertaba de nuevo, aunque el movimiento ya estuviera guardado.
    $second = $import->model(yapeRow(['Destino' => 'PANADERIA SAN MARTIN S.A.C.']));

    $transactions = Transaction::query()->where('user_id', $user->id)->get();

    expect($second)->toBeNull()
        ->and($transactions)->toHaveCount(1);
});

it('no confunde un ingreso con un gasto del mismo monto', function () {
    $user = User::factory()->create();
    $import = new TransactionYapeImport($user->id);

    // La contraparte se lee de columnas distintas segun el sentido, asi que en la
    // practica el Detail suele diferir. "Suele" no es una garantia, y lo que
    // habilita es colapsar plata que entra con plata que sale.
    $import->model(yapeRow(['Tipo de Transacción' => 'PAGASTE', 'Destino' => 'JUAN', 'Origen' => 'JUAN']));
    $import->model(yapeRow(['Tipo de Transacción' => 'RECIBISTE', 'Destino' => 'JUAN', 'Origen' => 'JUAN']));

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('sigue aceptando dos pagos distintos al mismo comercio con notas distintas', function () {
    $user = User::factory()->create();
    $import = new TransactionYapeImport($user->id);

    // El mensaje sigue discriminando: dos pagos iguales con notas distintas son
    // dos pagos. Arreglar los nulos no puede fusionar movimientos reales.
    $import->model(yapeRow(['Mensaje' => 'almuerzo']));
    $import->model(yapeRow(['Mensaje' => 'cena', 'Fecha de operación' => '15/03/2026 14:30:20']));

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(2);
});

// -------------------------------------------------------- the defect under test

/**
 * `model()` only assigns `$dateOperation` when the string matches
 * `d/m/Y H:i:s`. Any other shape leaves it null, and `date_operation` is
 * NOT NULL in PostgreSQL, so the insert raises SQLSTATE 23502 and takes the
 * whole import down — not just the offending row.
 *
 * The `empty()` guard above already establishes the contract for a row the
 * mapper cannot use: return null and skip it. An unparseable date is the same
 * situation and must behave the same way.
 */
it('descarta la fila cuando la fecha no tiene el formato esperado', function (string $date) {
    $user = User::factory()->create();

    $model = (new TransactionYapeImport($user->id))->model(yapeRow([
        'Fecha de operación' => $date,
    ]));

    expect($model)->toBeNull();
    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
})->with([
    'ISO' => '2026-03-15 14:30:00',
    'sin hora' => '15/03/2026',
    'guiones' => '15-03-2026 14:30:00',
    'basura' => 'no es una fecha',
]);
