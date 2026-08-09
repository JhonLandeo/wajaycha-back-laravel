<?php

namespace App\Jobs;

use App\DTOs\TransactionDataDTO;
use App\Enums\ImportStatus;
use App\Models\Detail;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Services\Imports\StatementLineParser;
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
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;

class ProcessPdfImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

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

    protected CategorizationService $categorizationService;

    protected TransactionAnalyzer $transactionAnalyzer;

    public function __construct(int $importId, int $userId, string $storedPath, int $accountId, int $year, int $month, ?string $password)
    {
        $this->importId = $importId;
        $this->userId = $userId;
        $this->storedPath = $storedPath;
        $this->accountId = $accountId;
        $this->year = $year;
        $this->month = $month;
        $this->password = $password;
        $this->categorizationService = app(CategorizationService::class);
        $this->transactionAnalyzer = app(TransactionAnalyzer::class);
    }

    public function handle(): void
    {
        Import::where('id', $this->importId)->update(['status' => ImportStatus::PROCESSING]);
        $filePath = Storage::path($this->storedPath);

        // `decryptPdf()` escribe una copia SIN CONTRASEÑA del extracto en
        // storage/app/private/. Antes no se borraba nunca: ni al terminar bien ni
        // en el catch, así que cada import encriptado dejaba el estado de cuenta
        // en texto plano acumulándose en disco. Se limpia en el finally.
        $decryptedPath = null;

        try {
            DB::beginTransaction();
            if ($this->isEncrypted($filePath)) {
                $filePath = $decryptedPath = $this->decryptPdf($filePath, (string) $this->password);
            }

            $text = $this->extractTextFromPdf($filePath);
            if (empty($text)) {
                $text = (new TesseractOCR($filePath))->run();
            }

            $this->processParsedTransactions(
                app(StatementLineParser::class)->parse($text, $this->year, $this->month)
            );

            Import::where('id', $this->importId)->update(['status' => ImportStatus::COMPLETED]);
            DB::commit();
        } catch (Throwable $th) {
            Log::error("Error en Job ProcessPdfImport (ID: {$this->importId}): ".$th->getMessage());
            DB::rollBack();
            Import::where('id', $this->importId)->update([
                'status' => ImportStatus::FAILED,
                'error_message' => $th->getMessage(),
            ]);
        } finally {
            if ($decryptedPath !== null && is_file($decryptedPath)) {
                @unlink($decryptedPath);
            }
        }
    }

    /**
     * @param  list<TransactionDataDTO>  $transactionsData
     */
    private function processParsedTransactions(array $transactionsData): void
    {
        $yapeIdsFounds = [];
        foreach ($transactionsData as $txData) {
            $features = $this->transactionAnalyzer->analyze($txData->description);
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
                $finalCategoryId = $this->categorizationService->findCategory($this->userId, $detail);
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

    private function extractTextFromPdf(string $filePath): string
    {
        $parser = new Parser;
        try {
            return $parser->parseFile($filePath)->getText();
        } catch (\Exception $e) {
            return '';
        }
    }

    private function isEncrypted(string $filePath): bool
    {
        $pdf = new Fpdi;
        try {
            $pdf->setSourceFile($filePath);

            return false;
        } catch (\setasign\Fpdi\PdfParser\PdfParserException $e) {
            return true;
        }
    }

    private function decryptPdf(string $filePath, string $password): string
    {
        $decryptedPath = storage_path('app/private/'.uniqid('decrypted_').'.pdf');

        $command = sprintf(
            'qpdf --decrypt --password=%s %s %s',
            escapeshellarg($password),
            escapeshellarg($filePath),
            escapeshellarg($decryptedPath)
        );
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \Exception('No se pudo desencriptar el PDF. ¿Contraseña incorrecta?');
        }

        return $decryptedPath;
    }
}
