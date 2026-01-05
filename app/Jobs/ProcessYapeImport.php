<?php

namespace App\Jobs;

use App\Models\Detail;
use App\Models\TransactionYape;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\TransactionAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Carbon\Carbon;

class ProcessYapeImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    protected User $user;
    protected string $filePath;
    protected string $userNameToFilter;
    protected CategorizationService $categorizationService;
    protected TransactionAnalyzer $transactionAnalyzer;

    public function __construct(User $user, string $filePath, string $userNameToFilter)
    {
        $this->user = $user;
        $this->filePath = $filePath;
        $this->userNameToFilter = $userNameToFilter;
        $this->transactionAnalyzer = app(TransactionAnalyzer::class);
    }

    public function handle(CategorizationService $categorizationService): void
    {
        $this->categorizationService = $categorizationService;

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile(Storage::path($this->filePath));
            $text = $pdf->getText();
            $lines = explode("\n", $text);

            $this->parseLines($lines);
        } catch (\Exception $e) {
            Log::error("Error al procesar Yape: " . $e->getMessage());
        }
    }

    /**
     * Lógica principal para parsear el texto del PDF.
     * @param array<string> $lines
     */
    private function parseLines(array $lines): void
    {
        $regex = '/^(\d{1,2}\/\d{2}\/\d{4})\s+(\d{2}:\d{2}:\d{2})\s+(.+?)\s+(YAPEASTE|TE YAPEARON|RECARGA|YAPFASTE)\s+(.+)$/i';

        foreach ($lines as $line) {
            if (preg_match($regex, $line, $matches)) {
                $this->processTransaction($matches);
            }
        }
    }

    /**
     * Procesa una transacción parseada.
     * @param array<string> $matches
     */
    private function processTransaction(array $matches): void
    {
        Log::info('Matches: ' . var_export($matches, true));
        $date = Carbon::createFromFormat('d/m/Y', trim($matches[1]))->format('Y-m-d');
        $time = trim($matches[2]);
        $movementRaw = trim($matches[3]);
        $typeRaw = trim($matches[4]);
        $amountRaw = trim($matches[5]);
        $goodDescription = $this->getCleanDescription($movementRaw);

        if (empty($goodDescription)) {
            Log::warning("No se pudo extraer descripción de: $movementRaw");
            return;
        }

        $amount = floatval(str_replace(',', '', $amountRaw));
        $type = ($typeRaw === 'TE YAPEARON') ? 'income' : 'expense';
        $features = $this->transactionAnalyzer->analyze($goodDescription);
        Log::info('Features: ' . var_export($features, true));
        $detail = null;

        if ($features['entity']) {
            $detail = Detail::where('user_id', $this->user->id)
                ->where('operation_type', $features['type'])
                ->where('entity_clean', $features['entity'])
                ->first();
        }

        if (!$detail) {
            $detail = Detail::where('user_id', $this->user->id)
                ->where('description', $goodDescription)
                ->first();
        }

        if (!$detail) {
            $detail = Detail::create([
                'user_id' => $this->user->id,
                'description' => $goodDescription,
                'operation_type' => $features['type'],
                'entity_clean' => $features['entity']
            ]);
        }
        $finalCategoryId = $this->categorizationService->findCategory($this->user->id, $detail);
        
        TransactionYape::firstOrCreate([
            'user_id' => $this->user->id,
            'date_operation' => $date . ' ' . $time,
            'amount' => $amount,
            'detail_id' => $detail->id,
            'type_transaction' => $type,
            'category_id' => $finalCategoryId
        ]);
    }

    private function getCleanDescription(string $movementRaw): string
    {
        $filters = [
            $this->userNameToFilter,
            "JHON PERCY LANDEO SANCHEZ",
            "JHON P. LANDEO S."
        ];
        $cleanName = str_ireplace($filters, '', $movementRaw);

        return strtoupper(trim(preg_replace('/\s+/', ' ', $cleanName)));
    }
}
