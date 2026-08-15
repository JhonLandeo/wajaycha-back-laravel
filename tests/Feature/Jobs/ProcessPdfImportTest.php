<?php

declare(strict_types=1);

/**
 * `ProcessPdfImport::handle()` had no test at all, and that is not incidental —
 * it is why the defect this file pins survived. Reaching the method meant having
 * qpdf and Tesseract installed and a real encrypted BCP statement on disk, so
 * nobody reached it, and a transaction held open across a shell-out and an OCR
 * run looked exactly like a transaction held open across two inserts.
 *
 * Extracting `StatementTextExtractor` moved the part that needs binaries behind
 * a seam. What is left is reachable, and the first case below is the one that
 * matters: it asserts where the transaction ISN'T.
 */

use App\Enums\ImportStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Jobs\ProcessPdfImport;
use App\Models\Category;
use App\Models\Detail;
use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\EmbeddingService;
use App\Services\Imports\StatementLineParser;
use App\Services\Imports\StatementTextExtractor;
use App\Services\Reconciliation\DuplicateCandidateDetector;
use App\Services\TransactionAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * One statement line at the real column offsets: the charge column ends at 54,
 * the deposit column at 73, and the trailing space is load-bearing.
 *
 * Deliberately not named `bcpLine` — `StatementLineParserTest` already declares
 * a global function by that name and Pest loads every test file into the same
 * process.
 */
function pdfJobStatementLine(string $date, string $description, ?string $charge = null): string
{
    $line = str_pad("{$date} {$date} {$description}", 54);

    if ($charge !== null) {
        $line = substr($line, 0, 54 - strlen($charge)).$charge;
    }

    return str_pad($line, 73).' ';
}

function pdfJobStatementText(): string
{
    return implode("\n", [
        'BANCO DE CREDITO DEL PERU - ESTADO DE CUENTA',
        pdfJobStatementLine('01MAY', 'PANA BODE a 060684', '8.13'),
        pdfJobStatementLine('02MAY', 'GRIFO PRIMAX', '25.00'),
    ]);
}

/**
 * An extractor that never touches a binary, and reports what the database was
 * doing at the moment it was called.
 */
function pdfJobFakeExtractor(?callable $onExtract = null): StatementTextExtractor
{
    return new class($onExtract) extends StatementTextExtractor
    {
        /** @var callable|null */
        private $onExtract;

        public function __construct(?callable $onExtract)
        {
            $this->onExtract = $onExtract;
        }

        public function extract(string $absolutePath, ?string $password): string
        {
            if ($this->onExtract !== null) {
                return ($this->onExtract)($absolutePath, $password);
            }

            return pdfJobStatementText();
        }
    };
}

/** A categoriser that answers without reaching Gemini. */
function pdfJobCategorizer(?callable $onFind = null): CategorizationService
{
    return new class(app(EmbeddingService::class), $onFind) extends CategorizationService
    {
        /** @var callable|null */
        private $onFind;

        public function __construct(EmbeddingService $embeddings, ?callable $onFind)
        {
            parent::__construct($embeddings);

            $this->onFind = $onFind;
        }

        public function findCategory(int $userId, Detail $detail, ?string $message = null): ?int
        {
            if ($this->onFind !== null) {
                ($this->onFind)($detail);
            }

            return null;
        }
    };
}

function pdfJobRun(ProcessPdfImport $job, StatementTextExtractor $extractor, ?CategorizationService $categorizer = null): void
{
    // El detector es el de verdad, no un doble: lo que el job tiene que hacer con
    // un duplicado es lo que el detector decida, y un doble que siempre devuelve
    // null dejaria pasar la version de este job que no lo llama en absoluto.
    $job->handle(
        $extractor,
        new StatementLineParser,
        new TransactionAnalyzer,
        $categorizer ?? pdfJobCategorizer(),
        app(DuplicateCandidateDetector::class),
    );
}

/** @return array{0: User, 1: Import} */
function pdfJobFixture(): array
{
    // El job escribe `financial_entity_id => 1` a mano, asi que la fila 1 tiene
    // que existir o la FK rechaza el insert. El id se fuerza en vez de dejarlo
    // salir de la secuencia, y eso lo encontro este mismo test: las secuencias
    // de PostgreSQL no vuelven atras con el rollback de RefreshDatabase, asi que
    // a partir del segundo caso el id ya no era 1 y el job fallaba con una
    // violacion de clave foranea.
    //
    // En produccion la fila 1 es BCP y la siembra una migracion, asi que esto no
    // esta roto hoy. Esta apoyado en un id literal, que es distinto.
    $entity = FinancialEntity::factory()->create(['id' => 1]);
    $user = User::factory()->create();

    $import = Import::factory()->create([
        'user_id' => $user->id,
        'financial_entity_id' => $entity->id,
        'status' => ImportStatus::PROCESSING,
    ]);

    return [$user, $import];
}

// ---------------------------------------------------------------- el defecto

it('lee el PDF fuera de toda transaccion', function () {
    [, $import] = pdfJobFixture();

    $nivelDurenteLaLectura = null;
    $estadoDurenteLaLectura = null;

    $extractor = pdfJobFakeExtractor(function () use ($import, &$nivelDurenteLaLectura, &$estadoDurenteLaLectura) {
        $nivelDurenteLaLectura = DB::transactionLevel();
        $estadoDurenteLaLectura = Import::where('id', $import->id)->value('status');

        return pdfJobStatementText();
    });

    pdfJobRun(new ProcessPdfImport($import->id, $import->user_id, 'files/x.pdf', 1, 2023, 6, 'clave'), $extractor);

    // ESTE es el caso. qpdf, Fpdi y Tesseract corren aca adentro: si el nivel
    // fuera 1, una corrida de OCR de varios minutos mantendria una transaccion
    // de PostgreSQL abierta todo ese rato, con la conexion tomada y autovacuum
    // sin poder limpiar nada mas nuevo que ese snapshot.
    //
    // RefreshDatabase envuelve cada test en su propia transaccion, asi que el
    // piso no es cero.
    expect($nivelDurenteLaLectura)->toBe(DB::transactionLevel());

    // Y el usuario ya ve el import moviendose antes de que empiece lo lento.
    expect($estadoDurenteLaLectura)->toBe(ImportStatus::PROCESSING);
});

it('abre la transaccion recien para escribir', function () {
    [, $import] = pdfJobFixture();

    $nivelBase = DB::transactionLevel();
    $nivelAlPersistir = null;

    $categorizer = pdfJobCategorizer(function () use (&$nivelAlPersistir) {
        $nivelAlPersistir ??= DB::transactionLevel();
    });

    pdfJobRun(
        new ProcessPdfImport($import->id, $import->user_id, 'files/x.pdf', 1, 2023, 6, null),
        pdfJobFakeExtractor(),
        $categorizer,
    );

    // La transaccion no se elimino: se corrio de lugar. Los inserts y el cambio
    // de estado siguen aterrizando juntos o no aterrizando.
    expect($nivelAlPersistir)->toBe($nivelBase + 1);
});

// -------------------------------------------------------------- lo que hacia

it('registra los movimientos y deja el import completado', function () {
    [$user, $import] = pdfJobFixture();

    pdfJobRun(new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', 1, 2023, 6, null), pdfJobFakeExtractor());

    expect(Import::find($import->id)->status)->toBe(ImportStatus::COMPLETED)
        ->and(Transaction::where('user_id', $user->id)->count())->toBe(2);

    $primera = Transaction::where('user_id', $user->id)->orderBy('date_operation')->first();

    expect((float) $primera->amount)->toBe(8.13)
        ->and($primera->type_transaction)->toBe('expense')
        ->and($primera->source_type)->toBe('import_statement');
});

it('no deja nada a medias cuando la persistencia falla', function () {
    [$user, $import] = pdfJobFixture();

    $vistos = 0;

    $categorizer = pdfJobCategorizer(function () use (&$vistos) {
        $vistos++;

        if ($vistos === 2) {
            throw new RuntimeException('la base se cayo a mitad de camino');
        }
    });

    pdfJobRun(
        new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', 1, 2023, 6, null),
        pdfJobFakeExtractor(),
        $categorizer,
    );

    // El primer movimiento alcanzo a escribirse antes de que reventara el
    // segundo. Que no quede ni el Detail es lo que prueba que la transaccion
    // sigue cubriendo lo que tiene que cubrir.
    expect(Transaction::where('user_id', $user->id)->count())->toBe(0)
        ->and(Detail::where('user_id', $user->id)->count())->toBe(0)
        ->and(Import::find($import->id)->status)->toBe(ImportStatus::FAILED);
});

it('marca el import fallido cuando no puede leer el archivo', function () {
    [$user, $import] = pdfJobFixture();

    $nivelBase = DB::transactionLevel();

    $extractor = pdfJobFakeExtractor(function () {
        throw new RuntimeException('No se pudo desencriptar el PDF. ¿Contraseña incorrecta?');
    });

    pdfJobRun(new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', 1, 2023, 6, 'clave-mala'), $extractor);

    $fallido = Import::find($import->id);

    expect($fallido->status)->toBe(ImportStatus::FAILED)
        ->and($fallido->error_message)->toContain('desencriptar');

    // Un fallo antes de escribir no puede dejar una transaccion colgada.
    expect(DB::transactionLevel())->toBe($nivelBase);
});

// -------------------------------------------------------- duplicados entre puertas

/**
 * Un movimiento que ya estaba en la base antes de importar el extracto.
 *
 * A las 14:30 del mismo dia a proposito: el banco asienta a medianoche y la
 * persona pago a la tarde, asi que el par real nace con horas de diferencia. Ese
 * hueco es justamente lo que impide decidir por cercania.
 *
 * No se llama `movement` porque `DuplicateCandidateDetectorTest` ya declara una
 * funcion global con ese nombre y Pest carga todos los archivos en un proceso.
 */
function pdfJobMovement(User $user, SourceType $source, string $amount, string $at, ?int $categoryId = null): Transaction
{
    $detail = Detail::factory()->create(['user_id' => $user->id]);

    return Transaction::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $categoryId,
        'amount' => $amount,
        'type_transaction' => 'expense',
        'date_operation' => $at,
        'source_type' => $source->value,
    ]);
}

it('unifica la fila del extracto con el Yape que ya estaba', function () {
    [$user, $import] = pdfJobFixture();

    $yape = pdfJobMovement($user, SourceType::IMPORT_APP, '8.13', '2023-05-01 14:30:00');

    pdfJobRun(new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', 1, 2023, 6, null), pdfJobFakeExtractor());

    $estado = Transaction::where('user_id', $user->id)
        ->where('source_type', SourceType::IMPORT_STATEMENT->value)
        ->where('amount', '8.13')
        ->firstOrFail();

    $record = ReconciliationCandidate::where('transaction_id', $estado->id)->firstOrFail();

    // Lo unifica solo, pero lo deja escrito. Esa fila es la que hace que la
    // decision se pueda ver en la pantalla de conciliacion y deshacer despues; el
    // matcheo viejo movia el total sin dejar nada.
    expect($record->status)->toBe(ReconciliationStatus::CONFIRMED)
        ->and($record->resolved_by)->toBe(ResolvedBy::SYSTEM)
        // Y en la direccion correcta: el extracto es el libro por el que la plata
        // paso. El matcheo viejo hacia exactamente lo contrario.
        ->and($yape->fresh()->matched_transaction_id)->toBe($estado->id)
        ->and($estado->matched_transaction_id)->toBeNull();
});

it('pregunta cuando el movimiento ya habia entrado por una foto de Telegram', function () {
    [$user, $import] = pdfJobFixture();

    // Este es el caso que el matcheo viejo no veia: filtraba `import_app`, asi que
    // una captura duplicaba en silencio contra el extracto.
    $captura = pdfJobMovement($user, SourceType::CAPTURE, '8.13', '2023-05-01 14:30:00');

    pdfJobRun(new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', 1, 2023, 6, null), pdfJobFakeExtractor());

    $estado = Transaction::where('user_id', $user->id)
        ->where('source_type', SourceType::IMPORT_STATEMENT->value)
        ->where('amount', '8.13')
        ->firstOrFail();

    $record = ReconciliationCandidate::where('transaction_id', $estado->id)->firstOrFail();

    // Una foto contra un extracto no tiene medicion detras, asi que se pregunta. Y
    // mientras la pregunta esta abierta los dos siguen contando: un total inflado
    // que la interfaz marca es honesto, uno faltante no.
    expect($record->status)->toBe(ReconciliationStatus::PENDING)
        ->and($captura->fresh()->matched_transaction_id)->toBeNull()
        ->and($estado->matched_transaction_id)->toBeNull();
});

it('no vuelve a preguntar al reimportar el mismo extracto', function () {
    [$user, $import] = pdfJobFixture();

    pdfJobMovement($user, SourceType::CAPTURE, '8.13', '2023-05-01 14:30:00');

    $correr = fn () => pdfJobRun(
        new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', 1, 2023, 6, null),
        pdfJobFakeExtractor(),
    );

    $correr();
    $correr();

    // Exportar de nuevo un periodo ya cargado es la forma normal de usar esto. La
    // fila no se duplica y la sospecha tampoco: preguntar dos veces por el mismo
    // par es pedirle a la persona que resuelva algo que ya resolvio.
    expect(Transaction::where('user_id', $user->id)->where('source_type', SourceType::IMPORT_STATEMENT->value)->count())->toBe(2)
        ->and(ReconciliationCandidate::where('user_id', $user->id)->count())->toBe(1);
});

it('hereda la categoria del movimiento con el que se unifico en vez de volver a categorizar', function () {
    [$user, $import] = pdfJobFixture();

    $categoria = Category::factory()->create(['user_id' => $user->id]);
    pdfJobMovement($user, SourceType::IMPORT_APP, '8.13', '2023-05-01 14:30:00', $categoria->id);

    $categorizados = [];
    $categorizer = pdfJobCategorizer(function (Detail $detail) use (&$categorizados) {
        $categorizados[] = $detail->description;
    });

    pdfJobRun(
        new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', 1, 2023, 6, null),
        pdfJobFakeExtractor(),
        $categorizer,
    );

    $estado = Transaction::where('user_id', $user->id)
        ->where('source_type', SourceType::IMPORT_STATEMENT->value)
        ->where('amount', '8.13')
        ->firstOrFail();

    // La otra fila es el MISMO movimiento, asi que su categoria no es una
    // conjetura: es la respuesta, ya pagada. `findCategory()` sale a Gemini por
    // cada comercio sin regla y un extracto son cientos de lineas — sobre los datos
    // reales, tres de cada cuatro se unifican, asi que esto es la diferencia entre
    // una llamada y cuatro.
    expect($estado->category_id)->toBe($categoria->id)
        ->and($categorizados)->not->toContain('PANA BODE a 060684')
        // La otra linea del extracto no se unifico con nada y si tuvo que salir.
        ->and($categorizados)->toContain('GRIFO PRIMAX');
});

it('marca los movimientos con el banco del extracto, no con el primero de la tabla', function () {
    [$user, $import] = pdfJobFixture();

    $otroBanco = FinancialEntity::factory()->create(['name' => 'Interbank']);

    pdfJobRun(
        new ProcessPdfImport($import->id, $user->id, 'files/x.pdf', $otroBanco->id, 2023, 6, null),
        pdfJobFakeExtractor(),
    );

    // El job siempre recibio la entidad y siempre escribio 1. Mientras nadie leyera
    // la columna era un dato sucio; ahora el detector decide con ella si dos filas
    // son la misma plata, y un extracto de Interbank marcado como BCP se unificaria
    // contra pagos de Yape que nunca lo tocaron.
    expect(Transaction::where('user_id', $user->id)->pluck('financial_entity_id')->unique()->all())
        ->toBe([$otroBanco->id]);
});

it('le pasa al extractor la ruta resuelta y la contrasena del job', function () {
    [$user, $import] = pdfJobFixture();

    $rutaRecibida = null;
    $claveRecibida = null;

    $extractor = pdfJobFakeExtractor(function (string $ruta, ?string $clave) use (&$rutaRecibida, &$claveRecibida) {
        $rutaRecibida = $ruta;
        $claveRecibida = $clave;

        return pdfJobStatementText();
    });

    pdfJobRun(new ProcessPdfImport($import->id, $user->id, 'files/extracto.pdf', 1, 2023, 6, 'secreto'), $extractor);

    expect($rutaRecibida)->toEndWith('files/extracto.pdf')
        ->and($claveRecibida)->toBe('secreto');
});

// ------------------------------------------- el pago repetido dentro del dia

/**
 * El mismo comercio, el mismo monto, el mismo dia, DOS VECES. Es lo que imprime
 * un extracto cuando la persona pago dos veces lo mismo, y como el PDF no trae
 * hora, las dos lineas son indistinguibles entre si.
 */
function pdfJobRepeatedDayExtractor(): StatementTextExtractor
{
    return pdfJobFakeExtractor(fn (): string => implode("\n", [
        'BANCO DE CREDITO DEL PERU - ESTADO DE CUENTA',
        pdfJobStatementLine('12JUN', 'Pago YAPE a Marleny Lim', '12.00'),
        pdfJobStatementLine('12JUN', 'Pago YAPE a Marleny Lim', '12.00'),
    ]));
}

function pdfJobRunRepeatedDay(int $importId, int $userId): void
{
    pdfJobRun(
        new ProcessPdfImport($importId, $userId, 'files/x.pdf', 1, 2026, 6, null),
        pdfJobRepeatedDayExtractor(),
    );
}

it('escribe las dos lineas de un pago repetido en el mismo dia', function () {
    [$user, $import] = pdfJobFixture();

    pdfJobRunRepeatedDay($import->id, $user->id);

    // Dos, no una. `firstOrCreate` daba la segunda por ya importada porque coincidia
    // en las cinco columnas de la clave, y la descartaba sin dejar rastro.
    expect(Transaction::where('user_id', $user->id)->count())->toBe(2);
});

it('no vuelve a escribirlas al reimportar el mismo extracto', function () {
    [$user, $import] = pdfJobFixture();

    pdfJobRunRepeatedDay($import->id, $user->id);
    pdfJobRunRepeatedDay($import->id, $user->id);

    // La idempotencia es lo que el conteo tenia que conservar: el extracto pide 2,
    // la base tiene 2, no se escribe nada.
    expect(Transaction::where('user_id', $user->id)->count())->toBe(2);
});

it('completa la linea que una importacion anterior dejo sin escribir', function () {
    [$user, $import] = pdfJobFixture();

    // El estado que dejo el defecto en produccion: una sola de las dos lineas.
    $detail = Detail::create([
        'user_id' => $user->id,
        'description' => 'Pago YAPE a Marleny Lim',
        'operation_type' => 'YAPE',
        'entity_clean' => 'Marleny Lim',
    ]);

    Transaction::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'date_operation' => '2026-06-12',
        'amount' => 12.00,
        'type_transaction' => 'expense',
        'source_type' => SourceType::IMPORT_STATEMENT->value,
        'financial_entity_id' => 1,
    ]);

    pdfJobRunRepeatedDay($import->id, $user->id);

    // Pide 2, tiene 1, escribe la que falta. La base se repara sola.
    expect(Transaction::where('user_id', $user->id)->count())->toBe(2);
});

it('no cuenta como linea del extracto una fila de otra fuente', function () {
    [$user, $import] = pdfJobFixture();

    $detail = Detail::create([
        'user_id' => $user->id,
        'description' => 'Pago YAPE a Marleny Lim',
        'operation_type' => 'YAPE',
        'entity_clean' => 'Marleny Lim',
    ]);

    // Una carga manual que cae exactamente en la misma clave. La version anterior no
    // filtraba por fuente, asi que esta fila suprimia un asiento del banco en
    // silencio.
    Transaction::create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'date_operation' => '2026-06-12',
        'amount' => 12.00,
        'type_transaction' => 'expense',
        'source_type' => SourceType::MANUAL->value,
        'financial_entity_id' => 1,
    ]);

    pdfJobRunRepeatedDay($import->id, $user->id);

    expect(Transaction::where('user_id', $user->id)
        ->where('source_type', SourceType::IMPORT_STATEMENT->value)
        ->count())->toBe(2);
});
