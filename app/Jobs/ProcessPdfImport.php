<?php

namespace App\Jobs;

use App\DTOs\TransactionDataDTO;
use App\Models\Detail;
use App\Models\Import;
use App\Enums\ImportStatus;
use App\Models\Transaction;
use App\Models\TransactionTag;
use App\Services\CategorizationService;
use App\Services\TransactionAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;
use setasign\Fpdi\Fpdi;
use Carbon\Carbon;
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
    protected string $password;
    protected CategorizationService $categorizationService;
    protected TransactionAnalyzer $transactionAnalyzer;

    public function __construct(int $importId, int $userId, string $storedPath, int $accountId, int $year, ?string $password)
    {
        $this->importId = $importId;
        $this->userId = $userId;
        $this->storedPath = $storedPath;
        $this->accountId = $accountId;
        $this->year = $year;
        $this->password = $password;
        $this->categorizationService = app(CategorizationService::class);
        $this->transactionAnalyzer = app(TransactionAnalyzer::class);
    }

    public function handle(): void
    {
        Import::where('id', $this->importId)->update(['status' => ImportStatus::PROCESSING]);
        $filePath = Storage::path($this->storedPath);

        try {
            DB::beginTransaction();
            if ($this->isEncrypted($filePath)) {
                $filePath = $this->decryptPdf($filePath, $this->password);
            }

            $text = $this->extractTextFromPdf($filePath);
            if (empty($text)) {
                $text = (new TesseractOCR($filePath))->run();
            }

            $lines = explode("\n", $text);
            $parsedTransactions = [];

            foreach ($lines as $line) {
                if (preg_match('/(\d{2}[A-Z]{3})\s+(\d{2}[A-Z]{3})/', $line, $matches)) {
                    $line_subtracted = explode(" ", substr($line, 0, -1));
                    $description = '';
                    for ($i = 2; $i < count($line_subtracted); $i++) {
                        if (trim($line_subtracted[$i]) === '') break;
                        $description .= $line_subtracted[$i] . ' ';
                    }
                    $description = trim($description);
                    $income = $expense = null;

                    foreach ($line_subtracted as $index => $item) {
                        $itemCleaned = $item;
                        if (strpos($itemCleaned, '.') !== false) {
                            if ($index === count($line_subtracted) - 1) $income = $itemCleaned;
                            else $expense = $itemCleaned;
                        }
                    }
            
                    $income = $income ?? 0;
                    $expense = $expense ?? 0;
                    $day = substr($line_subtracted[1], 0, 2);
                    $month = substr($line_subtracted[1], 2);
                    $monthFormat = $month == 'SET' ? 'SEP' : $month;
                    $dayMonth = $day . $monthFormat;
                    $parsedTransactions[] = new TransactionDataDTO(
                        amount: $income == 0 ? floatval(str_replace(',', '', $expense)) : floatval(str_replace(',', '', $income)),
                        date_operation: Carbon::createFromLocaleFormat('dM', 'es', $dayMonth)->setYear($this->year)->format('Y-m-d'),
                        type_transaction: $income == 0 ? 'expense' : 'income',
                        description: $description
                    );
                }
            }


            $this->processParsedTransactions($parsedTransactions);

            Import::where('id', $this->importId)->update(['status' => ImportStatus::COMPLETED]);
            DB::commit();
        } catch (Throwable $th) {
            Log::error("Error en Job ProcessPdfImport (ID: {$this->importId}): " . $th->getMessage());
            DB::rollBack();
            Import::where('id', $this->importId)->update([
                'status' => ImportStatus::FAILED,
                'error_message' => $th->getMessage()
            ]);
        }
    }

    /**
     * @param list<TransactionDataDTO> $transactionsData
     */
    private function processParsedTransactions(array $transactionsData): void
    {
        $yapeIdsFounds = [];
        foreach ($transactionsData as $txData) {
            $features = $this->transactionAnalyzer->analyze($txData->description);
            $detail = Detail::where('user_id', $this->userId)
                ->whereRaw('LOWER(description) = ?', $features['sanitized_description'])
                ->first();

            if (!$detail) {
                $detail = Detail::create([
                    'user_id' => $this->userId,
                    'description' => $txData->description,
                    'operation_type' => $features['type'],
                    'entity_clean' => $features['entity']
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

            if (!$finalCategoryId) {
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
                DB::table('transaction_tag')
                    ->where('transaction_id', $finalYapeId)
                    ->update(['transaction_id' => $transaction->id]);
                
                // Opcionalmente, podemos marcar el Yape como matched para que no aparezca en reportes
                // $transactionYape->update(['source_type' => 'yape_matched']);
            }
        }
    }


    private function extractTextFromPdf(string $filePath): string
    {
        $parser = new Parser();
        try {
            return $parser->parseFile($filePath)->getText();
        } catch (\Exception $e) {
            return '';
        }
    }

    private function isEncrypted(string $filePath): bool
    {
        $pdf = new Fpdi();
        try {
            $pdf->setSourceFile($filePath);
            return false;
        } catch (\setasign\Fpdi\PdfParser\PdfParserException $e) {
            return true;
        }
    }

    private function decryptPdf(string $filePath, string $password): string
    {
        $decryptedPath = storage_path('app/private/' . uniqid('decrypted_') . '.pdf');

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
