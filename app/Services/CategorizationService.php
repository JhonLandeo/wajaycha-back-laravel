<?php

namespace App\Services;

use App\Models\Detail;
use App\Models\KeywordRule;
use App\Models\CategorizationRule;
use Illuminate\Support\Facades\DB; // Importante

class CategorizationService
{
    protected $embeddingService;

    // Inyectamos el nuevo servicio
    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    public function findCategory(int $userId, Detail $detail): ?int
    {
        // --- Prioridad 1: Regla Exacta (Sin cambios) ---
        $exactRule = CategorizationRule::where('user_id', $userId)
            ->where('detail_id', $detail->id)
            ->first();
        if ($exactRule) {
            return $exactRule->category_id;
        }

        // --- Prioridad 2: Regla por Keyword (Sin cambios) ---
        $keywordRules = KeywordRule::where('user_id', $userId)->get();
        $descriptionLower = strtolower($detail->description);

        foreach ($keywordRules as $rule) {
            if (str_contains($descriptionLower, strtolower($rule->keyword))) {
                $this->createExactRule($userId, $detail->id, $rule->category_id);
                return $rule->category_id;
            }
        }

        // --- ¡NUEVO! Prioridad 3: Búsqueda Vectorial ---

        // 1. Genera el vector para la *nueva* transacción
        $newEmbedding = $this->embeddingService->generate($detail->description);
        if (!$newEmbedding) {
            return null;
        }

        // --- ESTA ES LA CORRECCIÓN ---
        // 2. Convierte el array de PHP en un string de vector para Postgres
        $vectorString = '[' . implode(',', $newEmbedding) . ']';

        // 3. Busca el vector más cercano
        // Ahora pasamos DOS parámetros: el string del vector y el ID del usuario
        $result = Detail::query()
            ->select('last_used_category_id')
            // Usamos ->selectRaw con los dos bindings
            ->selectRaw('(embedding <=> ?) AS distance', [$vectorString])
            ->where('user_id', $userId) // Este es el segundo binding
            ->whereNotNull('embedding')
            ->whereNotNull('last_used_category_id')
            ->orderBy('distance', 'asc') // 0 es el más cercano
            ->first();

        // 3. Decide si la similitud es "suficientemente buena"
        // Este umbral (0.25) es algo que tendrás que "tunear".
        // Un valor más bajo es más estricto.
        $threshold = 0.3;

        if ($result && $result->distance < $threshold) {
            // ¡Éxito! Lo encontramos.
            // Creamos una regla exacta para no tener que buscar por vector la próxima vez.
            $this->createExactRule($userId, $detail->id, $result->last_used_category_id);
            return $result->last_used_category_id;
        }

        return null; // No se pudo categorizar
    }

    public function createExactRule(int $userId, int $detailId, int $categoryId): void
    {
        CategorizationRule::firstOrCreate(
            ['user_id' => $userId, 'detail_id' => $detailId],
            ['category_id' => $categoryId]
        );
    }
}
