<?php

namespace App\Jobs;

use App\DTOs\TransactionDataDTO;
use App\Enums\ImportStatus;
use App\Models\Detail;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Services\Imports\StatementLineParser;
use App\Services\Imports\StatementTextExtractor;
use App\Services\TransactionAnalyzer;
use Carbon\Carbon;
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
     * Nothing in that stretch touches the database. The transaction now starts
     * after the last slow call and covers exactly what it was always for — the
     * inserts and the status flip landing together or not at all.
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
    ): void {
        Import::where('id', $this->importId)->update(['status' => ImportStatus::PROCESSING]);

        try {
            // ---- Lento, de I/O, y fuera de toda transacción ----
            $text = $extractor->extract(Storage::path($this->storedPath), $this->password);
            $rows = $parser->parse($text, $this->year, $this->month);

            // ---- Recién acá se abre la transacción ----
            DB::transaction(function () use ($rows, $analyzer, $categorizer): void {
                $this->processParsedTransactions($rows, $analyzer, $categorizer);

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
     * @param  list<TransactionDataDTO>  $transactionsData
     */
    private function processParsedTransactions(
        array $transactionsData,
        TransactionAnalyzer $analyzer,
        CategorizationService $categorizer,
    ): void {
        $yapeIdsFounds = [];

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

            $finalCategoryId = null;
            $finalYapeId = null;

            // Buscamos un registro de Yape unificado que coincida
            $transactionYape = Transaction::where('user_id', $this->userId)
                ->where('source_type', 'import_app')
                ->whereDate('date_operation', Carbon::parse($txData->date_operation)->toDateString())
                ->where('amount', $txData->amount)
                ->where('type_transaction', $txData->type_transaction)
                ->whereNotIn('id', $yapeIdsFounds)
                ->first();

            if ($transactionYape) {
                $yapeIdsFounds[] = $transactionYape->id;
                $finalYapeId = $transactionYape->id;
                $finalCategoryId = $transactionYape->category_id;
            }

            if (! $finalCategoryId) {
                $finalCategoryId = $categorizer->findCategory($this->userId, $detail);
            }

            $transaction = Transaction::firstOrCreate(
                [
                    'user_id' => $this->userId,
                    'detail_id' => $detail->id,
                    'date_operation' => $txData->date_operation,
                    'amount' => $txData->amount,
                    'type_transaction' => $txData->type_transaction,
                ],
                [
                    'category_id' => $finalCategoryId,
                    'matched_transaction_id' => $finalYapeId,
                    'source_type' => 'import_statement',
                    'financial_entity_id' => 1,
                ]
            );

            // 3. Actualizar las etiquetas si es necesario
            if ($finalYapeId) {
                // Como ahora todo está en transaction_tag.transaction_id,
                // movemos los tags del Yape a la transacción manual si aplica
                app(\App\Repositories\Contracts\TransactionRepositoryContract::class)
                    ->reassignTags($finalYapeId, $transaction->id);

                // Opcionalmente, podemos marcar el Yape como matched para que no aparezca en reportes
                // $transactionYape->update(['source_type' => 'yape_matched']);
            }
        }
    }
}
