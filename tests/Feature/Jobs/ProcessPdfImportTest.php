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
use App\Jobs\ProcessPdfImport;
use App\Models\Detail;
use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\EmbeddingService;
use App\Services\Imports\StatementLineParser;
use App\Services\Imports\StatementTextExtractor;
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
    $job->handle(
        $extractor,
        new StatementLineParser,
        new TransactionAnalyzer,
        $categorizer ?? pdfJobCategorizer(),
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
