<?php

namespace App\Jobs;

use App\Models\TransactionYape;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuggestYapeCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Aumentamos el timeout. Este job puede ser lento.
    public $timeout = 600; // 10 minutos

    protected $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    public function handle(): void
    {
        Log::info("Iniciando SuggestYapeCategoriesJob para User: {$this->userId}");

        // 1. Obtenemos todas las transacciones de Yape SIN categoría
        $uncategorizedTxs = TransactionYape::where('user_id', $this->userId)
            ->whereNull('category_id')
            ->get();

        if ($uncategorizedTxs->isEmpty()) {
            Log::info("No hay Yapes sin categorizar para User: {$this->userId}");
            return;
        }

        // 2. Iteramos por cada una para encontrar su "vecino más cercano"
        foreach ($uncategorizedTxs as $tx_new) {
            
            $hour = Carbon::parse($tx_new->date_operation)->hour;
            $dayOfWeek = Carbon::parse($tx_new->date_operation)->dayOfWeek; // 0=Domingo, 6=Sábado

            // 3. Ejecutamos la consulta k-NN
            $nearestNeighbor = TransactionYape::where('user_id', $this->userId)
                ->whereNotNull('category_id') // Solo buscar en las ya categorizadas
                
                // Filtro Fuerte: Solo buscar montos en un rango de +/- 50%
                // (Evita que un almuerzo de S/10 se compare con un pago de S/500)
                ->whereBetween('amount', [$tx_new->amount * 0.5, $tx_new->amount * 1.5]) 
                
                ->select('category_id')
                
                // 4. Cálculo de "Distancia" (El corazón de la lógica)
                // Ordenamos por la distancia más corta
                ->orderByRaw(
                    // (Distancia de Hora * Peso) + (Distancia de Monto * Peso) + (Distancia de Día * Peso)
                    
                    // Ponderamos la HORA como lo más importante (peso: 3)
                    '(ABS(EXTRACT(HOUR FROM date_operation) - ?)) * 3' .
                    
                    // Ponderamos el DÍA como segundo más importante (peso: 2)
                    // (Compara si es el mismo día de la semana)
                    '+ (ABS(EXTRACT(DOW FROM date_operation) - ?)) * 2' .
                    
                    // Ponderamos el MONTO como lo menos importante (peso: 1)
                    '+ (ABS(amount - ?)) * 1',
                    
                    [$hour, $dayOfWeek, $tx_new->amount]
                )
                ->first(); // Obtenemos el 1 más cercano

            // 5. Si encontramos un vecino, guardamos la sugerencia
            if ($nearestNeighbor) {
                $tx_new->suggested_category_id = $nearestNeighbor->category_id;
                $tx_new->save();
            }
        }
        
        Log::info("Finalizado SuggestYapeCategoriesJob para User: {$this->userId}");
    }
}