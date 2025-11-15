<?php

namespace App\Jobs;

use App\Models\Detail;
use App\Models\TransactionYape; // Tu tabla de Yapes
use App\Models\User;
use App\Services\CategorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
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

    public function __construct(User $user, string $filePath, string $userNameToFilter)
    {
        $this->user = $user;
        $this->filePath = $filePath;
        $this->userNameToFilter = $userNameToFilter;
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
        // El Regex para encontrar la línea principal de una transacción Yape
        // Captura: 1:Fecha, 2:Hora, 3:Movimientos, 4:Tipo, 5:Monto
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
        // 1. Limpia los datos extraídos
        $date = Carbon::createFromFormat('d/m/Y', trim($matches[1]))->format('Y-m-d');
        $time = trim($matches[2]);
        $movementRaw = trim($matches[3]);
        $typeRaw = trim($matches[4]);
        $amountRaw = trim($matches[5]);

        // 2. --- ¡LA LÓGICA CLAVE! ---
        //    Extrae el "detalle bueno" ignorando el nombre del usuario.
        $goodDescription = $this->getCleanDescription($movementRaw);

        if (empty($goodDescription)) {
            Log::warning("No se pudo extraer descripción de: $movementRaw");
            return; // Saltar esta transacción
        }

        // 3. Limpia el monto y el tipo
        $amount = floatval(str_replace(',', '', $amountRaw));
        $type = ($typeRaw === 'TE YAPEARON') ? 'income' : 'expense';

        // 4. --- LÓGICA DE BASE DE DATOS ---

        // a. Busca o crea el "Detail" (el cerebro de la IA)
        $detail = Detail::firstOrCreate([
            'user_id' => $this->user->id,
            'description' => $goodDescription
        ]);

        // b. Intenta auto-categorizar usando tu IA y Reglas
        // $categoryId = $this->categorizationService
        //     ->findCategory($this->user->id, $detail);

        // c. Inserta en tu tabla 'transactions_yapes'
        TransactionYape::firstOrCreate(
            [
                // Columnas de Búsqueda (para evitar duplicados)
                'user_id' => $this->user->id,
                'date_operation' => $date . ' ' . $time,
                'amount' => $amount,
                'detail_id' => $detail->id,
                'type_transaction' => $type,
            ],
            [
                // Columnas de Creación (datos nuevos)
                'type_transaction' => $type,
                // 'category_id' => $categoryId,
            ]
        );
    }

    /**
     * Filtra el nombre del usuario para obtener la contraparte.
     */
    private function getCleanDescription(string $movementRaw): string
    {
        // Reemplaza el nombre del usuario (y variantes comunes) con nada.
        // str_ireplace no es sensible a mayúsculas/minúsculas.

        // Lista de filtros: el nombre completo, y tal vez abreviaciones
        $filters = [
            $this->userNameToFilter, // "JHON PERCY LANDEO SANCHEZ"
            "JHON PERCY LANDEO SANCHEZ", // Detecté un typo en tus datos 
            "JHON P. LANDEO S." // Detecté abreviaciones [cite: 24]
        ];

        $cleanName = str_ireplace($filters, '', $movementRaw);

        // Limpia espacios extra y guiones
        return trim(preg_replace('/\s+/', ' ', $cleanName));
    }
}
