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
        // --- Prioridad 1: Regla Exacta ---
        $exactRule = CategorizationRule::where('user_id', $userId)
            ->where('detail_id', $detail->id)
            ->first();
        if ($exactRule) {
            return $exactRule->category_id;
        }

        // --- Prioridad 2: Regla por Keyword ---
        $keywordRules = KeywordRule::where('user_id', $userId)->get();
        $descriptionLower = strtolower($detail->description);

        foreach ($keywordRules as $rule) {
            if (str_contains($descriptionLower, strtolower($rule->keyword))) {
                $this->createExactRule($userId, $detail->id, $rule->category_id);
                return $rule->category_id;
            }
        }

        // --- Prioridad 3: Búsqueda Vectorial ---

        // 1. Genera el vector para la *nueva* transacción
        $newEmbedding = $this->embeddingService->generate($detail->description);
        if (!$newEmbedding) {
            return null;
        }

        $vectorString = '[' . implode(',', $newEmbedding) . ']';

        // 3. Busca el vector más cercano
        $result = Detail::query()
            ->select('last_used_category_id')
            ->selectRaw('(embedding <=> ?) AS distance', [$vectorString])
            ->where('user_id', $userId)
            ->whereNotNull('embedding')
            ->whereNotNull('last_used_category_id')
            ->orderBy('distance', 'asc') // 0 es el más cercano
            ->first();

        // 3. Decide si la similitud es "suficientemente buena"
        // Este umbral (0.25) es algo que tendrás que "tunear".
        // Un valor más bajo es más estricto.
        $threshold = 0.15;

        if ($result && $result->distance < $threshold) {
            $this->createExactRule($userId, $detail->id, $result->last_used_category_id);
            return $result->last_used_category_id;
        }

        return null;
    }

    public function createExactRule(int $userId, int $detailId, int $categoryId): void
    {
        CategorizationRule::firstOrCreate(
            ['user_id' => $userId, 'detail_id' => $detailId],
            ['category_id' => $categoryId]
        );
    }
}
