<?php

namespace App\Jobs;

use App\DTOs\TransactionDataDTO;
use App\Enums\ImportStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\SourceType;
use App\Models\Detail;
use App\Models\Import;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Services\Imports\StatementLineParser;
use App\Services\Imports\StatementTextExtractor;
use App\Services\Reconciliation\DuplicateCandidateDetector;
use App\Services\TransactionAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessPdfImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    /**
     * How many times the persistence transaction is replayed on a deadlock.
     *
     * This is the only retry in the job that can fire after the expensive part
     * is done. A deadlock is transient by definition and the text is already in
     * memory by then, so replaying costs one more round of inserts — not
     * another qpdf, another Fpdi and another Tesseract run.
     */
    private const PERSISTENCE_ATTEMPTS = 3;

    protected int $importId;

    protected int $userId;

    protected string $storedPath;

    /**
     * La entidad financiera del extracto — la que el usuario eligio al subirlo.
     *
     * `RegisterStatementImportAction` siempre paso este valor y este job siempre lo
     * ignoro, escribiendo `financial_entity_id => 1` a mano: todo extracto quedaba
     * marcado como BCP, viniera del banco que viniera. Mientras nadie leyera esa
     * columna era un dato sucio; desde que `DuplicateCandidateDetector` decide por
     * institucion, es un cargo de otro banco unificandose contra un pago de Yape.
     *
     * El nombre queda como esta a proposito, y no porque describa bien. Un job ya
     * encolado se restaura con `unserialize()` y `SerializesModels::__unserialize()`
     * saltea toda propiedad que no venga en el payload: renombrarla dejaria a los
     * payloads viejos con la propiedad sin inicializar, que es exactamente la
     * trampa que documenta `$month` mas abajo.
     */
    protected int $accountId;

    protected int $year;

    /**
     * Con default a propósito. Los jobs encolados se restauran con
     * `unserialize()`, que nunca pasa por el constructor, y
     * `SerializesModels::__unserialize()` saltea toda propiedad que no venga en
     * el payload. Un job encolado antes de que existiera `$month` quedaría con
     * la propiedad sin inicializar y leerla lanzaría
     * `Error: Typed property must not be accessed before initialization`
     * adentro del try, que el catch convierte en un import FAILED con un
     * mensaje incomprensible y sin reintento.
     *
     * El 0 significa "mes de emisión desconocido", y `StatementLineParser`
     * lo interpreta como el comportamiento anterior al cambio: no retroceder
     * el año. Un job viejo se procesa como se hubiera procesado antes.
     */
    protected int $month = 0;

    // Declarada `string` mientras la Action ya pasaba `?string`: construir el job
    // sin contraseña tiraba `TypeError: Cannot assign null to property of type
    // string` dentro de la transacción de RegisterStatementImportAction. Hoy no
    // se alcanza porque PdfRequest exige el campo, pero el día que la contraseña
    // sea opcional —no todo extracto viene encriptado— eso es un 500.
    protected ?string $password;

    public function __construct(int $importId, int $userId, string $storedPath, int $accountId, int $year, int $month, ?string $password)
    {
        $this->importId = $importId;
        $this->userId = $userId;
        $this->storedPath = $storedPath;
        $this->accountId = $accountId;
        $this->year = $year;
        $this->month = $month;
        $this->password = $password;
    }

    /**
     * Reads the statement, then writes what it found.
     *
     * The order of those two clauses is the whole point of this method. It used
     * to open a transaction as its first statement and commit as its last, so
     * the qpdf shell-out, the Fpdi probe, the PDF text extraction and a possible
     * Tesseract run all happened with a PostgreSQL transaction held open. On a
     * scanned statement that is minutes of `idle in transaction`: a pooled
     * connection pinned for the duration and autovacuum unable to reclaim any
     * row version newer than the snapshot, for a stretch bounded only by
     * `$timeout = 600`.
     *
     * Nothing in that stretch touches the database, so the transaction now
     * starts after the last of those calls rather than before the first.
     *
     * It is NOT yet pure persistence, and the earlier wording here claimed it
     * was. Two review lenses caught that independently, and they were right:
     * `processParsedTransactions()` calls `CategorizationService::findCategory()`
     * for every line without a matching rule, and that reaches
     * `EmbeddingService::generate()` — a synchronous Gemini request, inside the
     * open transaction, once per uncategorised merchant. A 73-line statement of
     * new merchants is that many network round trips against a held connection.
     *
     * That call was inside the transaction before this refactor too, so it is
     * not a regression; what was new was a comment saying it had been dealt
     * with. Removing it means either resolving categories before the
     * transaction opens or moving embedding generation onto the queue, both of
     * which are their own change. Recorded in
     * `docs/architecture/technical-debt.md` rather than left implied.
     *
     * The collaborators arrive through `handle()` rather than the constructor,
     * and not for style: a queued job serializes its own properties, so holding
     * services there wrote the whole object graph into every queue payload.
     * `$month`'s docblock above is the record of what unserialize semantics
     * already cost this class once.
     */
    public function handle(
        StatementTextExtractor $extractor,
        StatementLineParser $parser,
        TransactionAnalyzer $analyzer,
        CategorizationService $categorizer,
        DuplicateCandidateDetector $duplicates,
    ): void {
        Import::where('id', $this->importId)->update(['status' => ImportStatus::PROCESSING]);

        try {
            // ---- Lento, de I/O, y fuera de toda transacción ----
            $text = $extractor->extract(Storage::path($this->storedPath), $this->password);
            $rows = $parser->parse($text, $this->year, $this->month);

            // ---- Recién acá se abre la transacción ----
            DB::transaction(function () use ($rows, $analyzer, $categorizer, $duplicates): void {
                $this->processParsedTransactions($rows, $analyzer, $categorizer, $duplicates);

                Import::where('id', $this->importId)->update(['status' => ImportStatus::COMPLETED]);
            }, self::PERSISTENCE_ATTEMPTS);
        } catch (Throwable $th) {
            // El catch se queda con el fallo a propósito: un import fallido es un
            // estado que el usuario tiene que poder ver, no un job que desaparece
            // en failed_jobs. La consecuencia es que `$tries` de arriba sólo
            // cubre lo que pase ANTES de este try —el update a PROCESSING—, y no
            // reintenta nada de lo de adentro. Distinguir un fallo transitorio de
            // uno definitivo acá es un cambio propio: reintentar un PDF ilegible
            // tres veces son tres corridas de OCR para llegar al mismo FAILED.
            Log::error("Error en Job ProcessPdfImport (ID: {$this->importId}): ".$th->getMessage());

            Import::where('id', $this->importId)->update([
                'status' => ImportStatus::FAILED,
                'error_message' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Writes each parsed line, then asks whether the system already knew about it.
     *
     * This method used to carry its own duplicate matcher: same user, same amount,
     * same type, `whereDate` on the calendar day, first row wins. It predated
     * `DuplicateCandidateDetector` and disagreed with it on every point that
     * matters.
     *
     * It merged silently. It wrote `matched_transaction_id` straight onto the new
     * row, with no `reconciliation_candidates` entry behind it, so a movement
     * dropped out of the totals leaving nothing to see and nothing to undo. That
     * is the exact property the reconciliation design refuses to give up: deciding
     * without asking is only legitimate when the decision is visible and
     * reversible.
     *
     * It merged the wrong way round. The satellite was the statement row, so the
     * export kept counting and the bank's own record stopped — the reverse of
     * {@see \App\Enums\SourceType::authority()}, where the statement outranks
     * everything because it is the ledger the money actually moved through.
     *
     * It picked arbitrarily. `->first()` with no ordering, over a whole calendar
     * day: given two candidates, which one got merged came down to whatever
     * PostgreSQL returned first.
     *
     * And it was blind to Telegram. It filtered on `import_app`, so a payment
     * photographed at the till and then listed on the statement duplicated in
     * silence — the same blindness `TransactionYapeImport` had before 852c389.
     *
     * All four are now the detector's problem, which is where the measurements
     * behind the thresholds already live.
     *
     * @param  list<TransactionDataDTO>  $transactionsData
     */
    private function processParsedTransactions(
        array $transactionsData,
        TransactionAnalyzer $analyzer,
        CategorizationService $categorizer,
        DuplicateCandidateDetector $duplicates,
    ): void {
        /** @var array<string, int> $alreadyWritten */
        $alreadyWritten = [];

        foreach ($transactionsData as $txData) {
            $features = $analyzer->analyze($txData->description);
            $detail = Detail::where('user_id', $this->userId)
                ->whereRaw('LOWER(description) = ?', $features['sanitized_description'])
                ->first();

            if (! $detail) {
                $detail = Detail::create([
                    'user_id' => $this->userId,
                    'description' => $txData->description,
                    'operation_type' => $features['type'],
                    'entity_clean' => $features['entity'],
                ]);
            }

            $key = implode('|', [
                $detail->id,
                $txData->date_operation,
                $txData->amount,
                $txData->type_transaction,
            ]);

            $alreadyWritten[$key] ??= $this->writtenCountFor($detail->id, $txData);

            // Reimportar el mismo extracto no vuelve a escribir ni a preguntar: la
            // fila ya existe y ya paso por el detector cuando se creo, e
            // inspeccionarla de nuevo la ofreceria contra un candidato distinto del
            // que se descarto entonces.
            if ($alreadyWritten[$key] > 0) {
                $alreadyWritten[$key]--;

                continue;
            }

            $transaction = Transaction::create([
                'user_id' => $this->userId,
                'detail_id' => $detail->id,
                'date_operation' => $txData->date_operation,
                'amount' => $txData->amount,
                'type_transaction' => $txData->type_transaction,
                'source_type' => SourceType::IMPORT_STATEMENT->value,
                'financial_entity_id' => $this->accountId,
            ]);

            $record = $duplicates->inspect($transaction);

            $transaction->update([
                'category_id' => $this->inheritedCategory($record)
                    ?? $categorizer->findCategory($this->userId, $detail),
            ]);
        }
    }

    /**
     * Cuantas lineas de este movimiento ya estan escritas de importaciones previas.
     *
     * Un CONTEO y no un `firstOrCreate`, y esa diferencia es el defecto que esto
     * arregla. El extracto del BCP no trae hora, asi que dos pagos iguales al mismo
     * comercio el mismo dia son identicos en las cinco columnas que identificaban la
     * fila: el primero se escribia y el segundo se daba por ya importado y se
     * descartaba en silencio. El extracto decia 71 movimientos y entraban 70.
     *
     * El sintoma aparecia dos capas mas abajo. Con un solo asiento escrito, la
     * reconciliacion hacia lo correcto — un asiento explica un pago, y ni uno mas —
     * y el segundo pago de Yape quedaba contando solo, sin par. El bug se leia como
     * un problema de reconciliacion y no lo era.
     *
     * Contando, la idempotencia se conserva sin apoyarse en que las filas sean
     * distinguibles: el extracto pide N, la base tiene M, se escriben N-M. Reimportar
     * el mismo PDF pide 2 sobre 2 y no escribe nada. Y una base que ya sufrio el
     * defecto se repara sola en la siguiente importacion: pide 2, tiene 1, escribe la
     * que falta.
     *
     * Se cuentan solo filas `import_statement`. La pregunta es "cuantas lineas de
     * este extracto ya escribi", y una captura de Telegram o una carga manual no son
     * lineas de un extracto. La version anterior no filtraba, asi que una fila de
     * otra fuente que cayera en la misma clave suprimia el asiento del banco — y lo
     * suprimia en silencio, que es peor que tenerlo dos veces: si terminan siendo el
     * mismo movimiento, el detector lo propone y queda visible y reversible.
     *
     * Esto sigue siendo una inferencia sobre filas indistinguibles. La identidad de
     * verdad es el numero de operacion que el banco imprime en cada linea y que
     * `StatementLineParser::readDescription()` hoy descarta. Con esa columna la
     * idempotencia dejaria de deducirse; sin un extracto real a mano para confirmar
     * que el numero esta en todas las lineas, no se diseña sobre eso todavia.
     */
    private function writtenCountFor(int $detailId, TransactionDataDTO $txData): int
    {
        return Transaction::query()
            ->where('user_id', $this->userId)
            ->where('detail_id', $detailId)
            ->where('date_operation', $txData->date_operation)
            ->where('amount', $txData->amount)
            ->where('type_transaction', $txData->type_transaction)
            ->where('source_type', SourceType::IMPORT_STATEMENT->value)
            ->count();
    }

    /**
     * The category the counterpart already carries, when the system just merged
     * the two rows.
     *
     * Not thrift for its own sake. `CategorizationService::findCategory()` reaches
     * `EmbeddingService::generate()` — a synchronous Gemini request, inside the
     * open transaction — once per merchant it has no rule for, and a statement is
     * hundreds of lines. The old matcher avoided that call on every row it paired;
     * dropping it without replacement would have turned this fix into a slowdown
     * measured in network round trips against a held connection.
     *
     * Only on a CONFIRMED pair. A pending pair is a question, and taking the
     * category off a row the user has not yet agreed is the same movement would
     * answer it quietly on their behalf.
     */
    private function inheritedCategory(?ReconciliationCandidate $record): ?int
    {
        if ($record?->status !== ReconciliationStatus::CONFIRMED) {
            return null;
        }

        return Transaction::whereKey($record->candidate_transaction_id)->value('category_id');
    }
}
