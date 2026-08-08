<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ParetoClassification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ParetoRepositoryContract
{
    /**
     * Find a ParetoClassification belonging to $userId, or null.
     *
     * $userId is an AUTHORIZATION BOUNDARY, not a convenience filter. Resolving by id
     * alone returns another user's record, which is how every endpoint in this API came
     * to be exploitable. A caller that cannot supply the owner has no business calling
     * this method.
     */
    public function findById(int $id, int $userId): ?ParetoClassification;

    public function getAllForUser(int $userId): Collection;

    public function getMonthlyReport(int $userId, ?int $month, ?int $year, int $page, int $perPage): LengthAwarePaginator;

    /**
     * Categories assigned to a Pareto classification owned by $userId.
     *
     * $userId is an authorization boundary, not a filter of convenience: without it this
     * returns another user's category names and budgets.
     */
    public function getCategories(int $paretoId, int $userId, ?string $search = null): Collection;

    public function create(array $data): ParetoClassification;

    public function update(ParetoClassification $pareto, array $data): bool;

    public function delete(ParetoClassification $pareto): bool;
}
