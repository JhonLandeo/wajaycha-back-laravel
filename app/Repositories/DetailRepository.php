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
        $centroid = Detail::query()
            ->where('user_id', $userId)
            ->where('last_used_category_id', $categoryId)
            ->whereNotNull('embedding')
            ->avg('embedding');

        if (! $centroid) {
            // No embedded detail carries this category yet, so there is nothing to be
            // near. Returning an empty page beats ordering by an arbitrary vector.
            return Detail::query()->whereRaw('1 = 0')->paginate($perPage, ['*'], 'page', $page);
        }

        $alreadyRuled = CategorizationRule::query()
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->pluck('detail_id');

        return Detail::query()
            ->where('user_id', $userId)
            ->whereNull('last_used_category_id')
            ->whereNotNull('embedding')
            ->whereNotIn('id', $alreadyRuled)
            ->orderByRaw('embedding <=> ?', [$centroid])
            ->limit(100)
            ->select('id', 'description')
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
