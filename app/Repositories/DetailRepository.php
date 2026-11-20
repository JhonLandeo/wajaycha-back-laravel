<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CategorizationRule;
use App\Models\Detail;
use App\Repositories\Contracts\DetailRepositoryContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL adapter for the Detail catalogue read model.
 *
 * The query is the one the controller used to hold, moved without alteration.
 */
class DetailRepository implements DetailRepositoryContract
{
    /**
     * Similitud mínima para proponer un comercio sin categorizar.
     *
     * Más baja que el 0.4 de la cascada: acá no se asigna nada, se sugiere, y el
     * usuario confirma. El valor coincide con el umbral por defecto de `%` en
     * pg_trgm, que es el que usa el índice GIN.
     */
    private const SUGGESTION_MIN_SIMILARITY = 0.3;

    /**
     * Cuántos comercios ya categorizados representan a la categoría.
     *
     * El cruce es candidatos × ejemplos. Sin tope, una categoría con cientos de
     * movimientos convierte una sugerencia en un producto cartesiano grande.
     */
    private const SUGGESTION_EXAMPLE_LIMIT = 50;

    /**
     * @return array<int, object{id: int, name: string, created_at: string, category_name: ?string, total_count: int}>
     */
    public function listForUser(int $userId, int $perPage, int $page): array
    {
        /** @var array<int, object{id: int, name: string, created_at: string, category_name: ?string, total_count: int}> */
        return DB::select('SELECT * FROM get_details(?, ?, ?)', [
            $perPage,
            $page,
            $userId,
        ]);
    }

    public function paginateRulesForCategory(
        int $userId,
        int $categoryId,
        int $perPage,
        int $page
    ): LengthAwarePaginator {
        return Detail::query()
            ->join('categorization_rules as cr', 'details.id', '=', 'cr.detail_id')
            ->where('cr.category_id', $categoryId)
            ->where('cr.user_id', $userId)
            ->select('details.id', 'details.description')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function paginateSuggestionsForCategory(
        int $userId,
        int $categoryId,
        int $perPage,
        int $page
    ): LengthAwarePaginator {
        $alreadyRuled = CategorizationRule::query()
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->pluck('detail_id');

        // Los comercios que el usuario ya puso en esta categoría son el patrón
        // contra el que se compara. Los más recientes primero: son los que mejor
        // representan lo que el usuario entiende hoy por esa categoría.
        $examples = Detail::query()
            ->selectRaw('coalesce(entity_clean, description) AS text')
            ->where('user_id', $userId)
            ->where('last_used_category_id', $categoryId)
            ->orderByDesc('updated_at')
            ->limit(self::SUGGESTION_EXAMPLE_LIMIT);

        return Detail::query()
            ->crossJoinSub($examples, 'examples')
            ->where('details.user_id', $userId)
            ->whereNull('details.last_used_category_id')
            ->whereNotIn('details.id', $alreadyRuled)
            ->whereRaw('coalesce(details.entity_clean, details.description) % examples.text')
            ->whereRaw(
                'similarity(coalesce(details.entity_clean, details.description), examples.text) > ?',
                [self::SUGGESTION_MIN_SIMILARITY]
            )
            ->select('details.id', 'details.description')
            ->selectRaw(
                'max(similarity(coalesce(details.entity_clean, details.description), examples.text)) AS score'
            )
            ->groupBy('details.id', 'details.description')
            ->orderByDesc('score')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function updateClassification(int $detailId, ?string $operationType, ?string $entityClean): void
    {
        DB::table('details')
            ->where('id', $detailId)
            ->update([
                'operation_type' => $operationType,
                'entity_clean' => $entityClean,
            ]);
    }
}
