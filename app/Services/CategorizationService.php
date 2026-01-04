<?php

namespace App\Services;

use App\Models\Detail;
use App\Models\KeywordRule;
use App\Models\CategorizationRule;

class CategorizationService
{
    protected EmbeddingService $embeddingService;

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
        // Buscar el mas semejante por descripción
        $similarDetail = Detail::where('user_id', $userId)
            ->where('id', '!=', $detail->id)
            ->whereRaw('description ILIKE ?', ["%{$detail->description}%"])
            ->whereNotNull('last_used_category_id')
            ->first();

        // Buscar la regla exacta para el detalle similar encontrado
        if ($similarDetail) {
            $exactSimilarRule = CategorizationRule::where('user_id', $userId)
                ->where('detail_id', $similarDetail->id)
                ->first();

            if ($exactSimilarRule) {
                $this->createExactRule($userId, $detail->id, $exactSimilarRule->category_id);
                return $exactSimilarRule->category_id;
            }
        }

        if ($similarDetail) {
            $this->createExactRule($userId, $detail->id, $similarDetail->last_used_category_id);
            return $similarDetail->last_used_category_id;
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
        // Genera el vector para la *nueva* transacción
        $newEmbedding = $this->embeddingService->generate($detail->description);
        if (!$newEmbedding) {
            return null;
        }

        $vectorString = '[' . implode(',', $newEmbedding) . ']';
        $result = Detail::query()
            ->select('last_used_category_id')
            ->selectRaw('(embedding <=> ?) AS distance', [$vectorString])
            ->where('user_id', $userId)
            ->whereNotNull('embedding')
            ->whereNotNull('last_used_category_id')
            ->orderBy('distance', 'asc') // 0 es el más cercano
            ->first();

        // Decide si la similitud es "suficientemente buena"
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
