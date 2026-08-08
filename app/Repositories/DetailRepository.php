<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\DetailRepositoryContract;
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
