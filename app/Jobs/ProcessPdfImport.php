<?php

namespace App\Jobs;

use App\DTOs\TransactionDataDTO;
use App\Models\Detail;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\TransactionTag;
use App\Models\TransactionYape;
use App\Services\CategorizationService; // <-- Nuestro nuevo servicio
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

    protected int $importId;
    protected int $userId;
    protected string $storedPath;
    protected int $accountId;
    protected int $year;
    protected string $password;
    protected CategorizationService $categorizationService;

    public function __construct(int $importId, int $userId, string $storedPath, int $accountId, int $year, ?string $password)
    {
        $this->importId = $importId;
        $this->userId = $userId;
        $this->storedPath = $storedPath;
        $this->accountId = $accountId;
        $this->year = $year;
        $this->password = $password;
        $this->categorizationService = app(CategorizationService::class);
    }

    public function handle(): void
    {
        // 1. Marcar el Job como 'processing'
        Import::where('id', $this->importId)->update(['status' => 'processing']);
        $filePath = Storage::path($this->storedPath); // Ruta absoluta

        try {
            // 2. Desencriptar si es necesario
            if ($this->isEncrypted($filePath)) {
                $filePath = $this->decryptPdf($filePath, $this->password);
            }

            // 3. Extraer texto (PDF o OCR)
            $text = $this->extractTextFromPdf($filePath);
            if (empty($text)) {
                $text = (new TesseractOCR($filePath))->run();
            }

            $lines = explode("\n", $text);
            $parsedTransactions = [];

            // 4. Parsear líneas (tu lógica)
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


            // 5. Procesar e insertar transacciones
            $this->processParsedTransactions($parsedTransactions);

            // 6. Marcar como 'completed'
            Import::where('id', $this->importId)->update(['status' => 'completed']);
        } catch (Throwable $th) {
            Log::error("Error en Job ProcessPdfImport (ID: {$this->importId}): " . $th->getMessage());
            Import::where('id', $this->importId)->update([
                'status' => 'failed',
                'error_message' => $th->getMessage()
            ]);
        }
    }

    /**
     * @param list<TransactionDataDTO> $transactionsData
     */
    private function processParsedTransactions(array $transactionsData): void
    {
        foreach ($transactionsData as $txData) {
            $detail = Detail::firstOrCreate(
                ['user_id' => $this->userId, 'description' => $txData->description],
                ['merchant_id' => null]
            );

            $finalCategoryId = null;
            $finalYapeId = null;

            // 2. --- Prioridad 0: Yape Matcher
            //    Buscamos un Yape que ya esté categorizado por el usuario.
            $transactionYape = TransactionYape::where('user_id', $this->userId)
                ->whereDate('date_operation', Carbon::parse($txData->date_operation)->toDateString())
                ->where('amount', $txData->amount)
                ->where('type_transaction', $txData->type_transaction)
                ->whereNotNull('category_id')
                ->first();

            if ($transactionYape) {
                $finalCategoryId = $transactionYape->category_id;
                $finalYapeId = $transactionYape->id;
            } else {
                $finalCategoryId = $this->categorizationService->findCategory($this->userId, $detail);
            }

            $transasction = Transaction::firstOrCreate(
                [
                    // --- ARRAY DE BÚSQUEDA ---
                    'user_id' => $this->userId,
                    'detail_id' => $detail->id,
                    'date_operation' => $txData->date_operation,
                    'amount' => $txData->amount,
                    'type_transaction' => $txData->type_transaction,
                ],
                [
                    // --- ARRAY DE CREACIÓN ---
                    // 'account_id' => $this->accountId,
                    'category_id' => $finalCategoryId, // La categoría que gan_ó (de P0 o P1-P3)
                    'yape_id' => $finalYapeId,       // El ID del Yape (será null si P0 falló)
                ]
            );

            // 3. Actualizar las etiquetas si es necesario
            if ($finalYapeId) {
                $yapeTag = TransactionYape::find($finalYapeId)->tags()->first();
                if ($yapeTag) {
                    $transactionTag = new TransactionTag();
                    $transactionTag->transaction_id = $transasction->id;
                    $transactionTag->save();
                }
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
