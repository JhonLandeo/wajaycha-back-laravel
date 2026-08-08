<?php

namespace App\Services;

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Detail;
use App\Models\KeywordRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategorizationService
{
    protected EmbeddingService $embeddingService;

    const THRESHOLD_VECTOR = 0.15;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    // AÑADIMOS $message como parámetro opcional
    public function findCategory(int $userId, Detail $detail, ?string $message = null): ?int
    {
        Log::info('Mensaje para categorización: '.($message ?? 'Ninguno'));
        // 1. Prioridad Absoluta: Regla Exacta Histórica (Por ID de Detalle)
        $exactRule = CategorizationRule::where('user_id', $userId)
            ->where('detail_id', $detail->id)
            ->first();

        if ($exactRule) {
            return $exactRule->category_id;
        }

        // Cargamos las reglas de palabras clave (cachear esto sería ideal si son muchas)
        $keywordRules = KeywordRule::where('user_id', $userId)->get();

        // Si el mensaje es taxi y la categoria es tambien taxi, entonces hacemos match aunque la entidad diga "Bodega El Chino". --- IGNORE ---
        if (! empty($message)) {
            $categoryIdByMessage = Category::where('user_id', $userId)
                ->where('name', 'ilike', '%'.$message.'%')
                ->whereNotNull('parent_id')
                ->value('id');

            Log::info("Buscando categoría por mensaje: '$message' -> Cat ID: ".($categoryIdByMessage ?? 'No encontrado'));

            if ($categoryIdByMessage) {
                return $categoryIdByMessage;
            }
        }

        // 2. Prioridad: Búsqueda en el MENSAJE (Lo que pides)
        // Si el mensaje dice "Taxi a casa", y tienes regla "taxi" -> Transporte.
        if (! empty($message)) {
            $categoryByMessage = $this->analyzeTextForKeywords($message, $keywordRules);
            if ($categoryByMessage) {
                Log::info("✅ [CAT] Match por MENSAJE: '$message' -> Cat ID: $categoryByMessage");

                return $categoryByMessage;
            }
        }

        // 3. Prioridad: Búsqueda en la DESCRIPCIÓN/ENTIDAD
        // Si no hubo suerte en el mensaje, buscamos en "Bodega El Chino".
        $searchString = $detail->entity_clean ?? $detail->description;
        $categoryByEntity = $this->analyzeTextForKeywords($searchString, $keywordRules);

        if ($categoryByEntity) {
            Log::info("✅ [CAT] Match por ENTIDAD: '$searchString' -> Cat ID: $categoryByEntity");
            $this->rememberInferredRule($userId, $detail->id, $categoryByEntity);

            return $categoryByEntity;
        }

        Log::info('🤖 [CAT] Sin coincidencias de texto. Iniciando Vector Search...');

        $newEmbedding = $this->embeddingService->generate($detail->description); // Vectorizamos el texto limpio mejor

        if (! $newEmbedding) {
            return null;
        }

        $vectorString = '['.implode(',', $newEmbedding).']';

        $vectorMatch = Detail::query()
            ->select('last_used_category_id')
            ->selectRaw('(embedding <=> ?) AS distance', [$vectorString])
            ->where('user_id', $userId)
            ->whereNotNull('embedding')
            ->whereNotNull('last_used_category_id')
            ->orderBy('distance', 'asc') // 0 es idéntico
            ->limit(1)
            ->first();

        if ($vectorMatch && $vectorMatch->distance < self::THRESHOLD_VECTOR) {
            Log::info("✅ [CAT] Match Vectorial encontrado. Distancia: {$vectorMatch->distance}");
            $this->rememberInferredRule($userId, $detail->id, $vectorMatch->last_used_category_id);

            return $vectorMatch->last_used_category_id;
        }

        Log::info('❌ [CAT] No se encontró categoría.');

        return null;
    }

    /**
     * Busca palabras clave dentro de un texto.
     */
    /**
     * @param  \Illuminate\Support\Collection<int, KeywordRule>  $rules
     */
    private function analyzeTextForKeywords(string $text, \Illuminate\Support\Collection $rules): ?int
    {
        $text = Str::ascii(Str::lower($text));

        foreach ($rules as $rule) {
            $keyword = Str::ascii(Str::lower($rule->keyword));

            if (str_contains($keyword, ' ')) {
                if (str_contains($text, $keyword)) {
                    return $rule->category_id;
                }
            } else {
                if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $text)) {
                    return $rule->category_id;
                }
            }
        }

        return null;
    }

    /**
     * Records a rule the cascade INFERRED, and only if the merchant has none.
     *
     * This deliberately does not overwrite. The cascade infers from a keyword hit
     * or a trigram-close vector match, and a guess must never displace a decision
     * the user already made — otherwise a weak match silently rewrites their
     * correction and the merchant starts falling in the wrong place again.
     */
    public function rememberInferredRule(int $userId, int $detailId, int $categoryId): void
    {
        CategorizationRule::firstOrCreate(
            ['user_id' => $userId, 'detail_id' => $detailId],
            ['category_id' => $categoryId]
        );
    }

    /**
     * Sets the rule for a merchant because the USER said so, overwriting whatever
     * was there.
     *
     * This is the half that was missing. Both correction paths — editing a
     * transaction's category and syncing a rule from the SPA — used to call the
     * inferring method, whose `firstOrCreate` is a silent no-op once a rule
     * exists. So a user could correct the same merchant forever and the system
     * would keep re-applying its original guess, with nothing to show they had
     * disagreed.
     */
    public function setRule(int $userId, int $detailId, int $categoryId): void
    {
        CategorizationRule::updateOrCreate(
            ['user_id' => $userId, 'detail_id' => $detailId],
            ['category_id' => $categoryId]
        );
    }
}
