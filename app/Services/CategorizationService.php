<?php

namespace App\Services;

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Detail;
use App\Models\KeywordRule;
use App\Support\Redact;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategorizationService
{
    /**
     * Similitud mínima para heredar la categoría de un comercio parecido.
     *
     * `DetailResolver` usa 0.6 para decidir que dos textos son el MISMO comercio.
     * Acá el umbral es más bajo a propósito: la pregunta no es "¿es el mismo
     * local?" sino "¿comparte suficiente vocabulario para caer en la misma
     * categoría?" — "Bodega El Chino" y "Bodega Don Pepe" no son el mismo
     * comercio, pero sí la misma clase de gasto.
     */
    public const THRESHOLD_TRIGRAM = 0.4;

    public function findCategory(int $userId, Detail $detail, ?string $message = null): ?int
    {
        // Aca habia un
        //   Log::info('Mensaje para categorización: '.($message ?? 'Ninguno'));
        // que escribia entero lo que el usuario habia contado sobre su gasto. Se
        // elimina en vez de redactarse: las lineas de resultado de mas abajo ya
        // dicen que paso, asi que esta no aportaba nada que no se sepa despues.
        //
        // La linea se cita completa a proposito, con su fallback: la version
        // anterior de este comentario la citaba sin el, y buscarla asi en la
        // historia no encuentra nada.

        // 1. Prioridad Absoluta: Regla Exacta Histórica (Por ID de Detalle)
        $exactRule = CategorizationRule::where('user_id', $userId)
            ->where('detail_id', $detail->id)
            ->first();

        if ($exactRule) {
            $categoryId = (int) $exactRule->category_id;
            $this->rememberDetailCategory($detail, $categoryId);

            return $categoryId;
        }

        // Cargamos las reglas de palabras clave (cachear esto sería ideal si son muchas)
        $keywordRules = KeywordRule::where('user_id', $userId)->get();

        // Si el mensaje es taxi y la categoria es tambien taxi, entonces hacemos match aunque la entidad diga "Bodega El Chino". --- IGNORE ---
        if (! empty($message)) {
            $categoryIdByMessage = Category::where('user_id', $userId)
                ->where('name', 'ilike', '%'.$message.'%')
                ->whereNotNull('parent_id')
                ->value('id');

            Log::info('[CAT] Búsqueda por nombre de categoría '.Redact::text($message)
                .' -> Cat ID: '.($categoryIdByMessage ?? 'no encontrado'));

            if ($categoryIdByMessage) {
                $categoryId = (int) $categoryIdByMessage;
                $this->rememberDetailCategory($detail, $categoryId);

                return $categoryId;
            }
        }

        // 2. Prioridad: Búsqueda en el MENSAJE (Lo que pides)
        // Si el mensaje dice "Taxi a casa", y tienes regla "taxi" -> Transporte.
        if (! empty($message)) {
            $categoryByMessage = $this->analyzeTextForKeywords($message, $keywordRules);
            if ($categoryByMessage) {
                Log::info("✅ [CAT] Match por MENSAJE para el detail {$detail->id} -> Cat ID: {$categoryByMessage}");
                $this->rememberDetailCategory($detail, $categoryByMessage);

                return $categoryByMessage;
            }
        }

        // 3. Prioridad: Búsqueda en la DESCRIPCIÓN/ENTIDAD
        // Si no hubo suerte en el mensaje, buscamos en "Bodega El Chino".
        $searchString = $this->searchTextFor($detail);
        $categoryByEntity = $this->analyzeTextForKeywords($searchString, $keywordRules);

        if ($categoryByEntity) {
            // `$searchString` es el nombre del comercio. Se reporta el detail que
            // lo representa, que es lo que sirve para seguir el rastro.
            Log::info("✅ [CAT] Match por ENTIDAD del detail {$detail->id} -> Cat ID: {$categoryByEntity}");
            $this->rememberInferredRule($userId, (int) $detail->id, $categoryByEntity);

            return $categoryByEntity;
        }

        if ($searchString === '') {
            Log::info('❌ [CAT] No se encontró categoría.');

            return null;
        }

        Log::info('🔎 [CAT] Sin coincidencias de texto. Iniciando similitud por trigramas...');

        // El detalle nuevo hereda la categoría del comercio conocido más parecido.
        // `%` es el operador que puede resolver el índice GIN; `similarity()` solo
        // ordena y aplica el umbral fino.
        $trigramMatch = Detail::query()
            ->select('last_used_category_id')
            ->selectRaw('similarity(coalesce(entity_clean, description), ?) AS similarity', [$searchString])
            ->where('user_id', $userId)
            ->whereNotNull('last_used_category_id')
            ->where('id', '!=', $detail->id)
            ->whereRaw('coalesce(entity_clean, description) % ?', [$searchString])
            ->whereRaw('similarity(coalesce(entity_clean, description), ?) > ?', [$searchString, self::THRESHOLD_TRIGRAM])
            ->orderByRaw('similarity(coalesce(entity_clean, description), ?) DESC', [$searchString])
            ->limit(1)
            ->first();

        if ($trigramMatch) {
            $categoryId = (int) $trigramMatch->last_used_category_id;
            Log::info('✅ [CAT] Match por trigramas encontrado. Similitud: '.$trigramMatch->getAttribute('similarity'));
            $this->rememberInferredRule($userId, (int) $detail->id, $categoryId);

            return $categoryId;
        }

        Log::info('❌ [CAT] No se encontró categoría.');

        return null;
    }

    /**
     * El texto sobre el que se busca una entidad: el normalizado cuando existe.
     *
     * `description` es el texto crudo de la entidad financiera. `entity_clean` es
     * el resultado de quitarle el ruido, y es lo que el paso de entidad ya usaba;
     * la similitud por trigramas tiene que mirar lo mismo.
     */
    private function searchTextFor(Detail $detail): string
    {
        $clean = trim((string) ($detail->entity_clean ?? ''));

        return $clean !== '' ? $clean : trim((string) $detail->description);
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
     * or a trigram-close match, and a guess must never displace a decision the
     * user already made — otherwise a weak match silently rewrites their
     * correction and the merchant starts falling in the wrong place again.
     */
    public function rememberInferredRule(int $userId, int $detailId, int $categoryId): void
    {
        CategorizationRule::firstOrCreate(
            ['user_id' => $userId, 'detail_id' => $detailId],
            ['category_id' => $categoryId]
        );

        $detail = Detail::query()
            ->whereKey($detailId)
            ->where('user_id', $userId)
            ->first();

        if ($detail) {
            $this->rememberDetailCategory($detail, $categoryId);
        }
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

        $detail = Detail::query()
            ->whereKey($detailId)
            ->where('user_id', $userId)
            ->first();

        if ($detail) {
            $this->rememberDetailCategory($detail, $categoryId);
        }
    }

    /**
     * Recuerda la categoría que se le asignó a este comercio.
     *
     * `details.last_used_category_id` es la memoria que alimenta la cascada y la
     * pestaña de sugerencias. Antes solo la escribía el job de embeddings; ahora
     * cada resolución la deja al día sin depender de una llamada externa.
     */
    public function rememberDetailCategory(Detail $detail, int $categoryId): void
    {
        if (! $detail->exists) {
            return;
        }

        if ((int) $detail->last_used_category_id === $categoryId) {
            return;
        }

        $detail->forceFill(['last_used_category_id' => $categoryId])->save();
    }
}
