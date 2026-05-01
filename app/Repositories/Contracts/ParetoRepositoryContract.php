<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ParetoClassification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ParetoRepositoryContract
{
    public function findById(int $id): ?ParetoClassification;

    public function getAllForUser(int $userId): Collection;

    public function getMonthlyReport(int $userId, ?int $month, ?int $year, int $page, int $perPage): LengthAwarePaginator;

    public function getCategories(int $paretoId, ?string $search = null): Collection;

    public function create(array $data): ParetoClassification;

    public function update(ParetoClassification $pareto, array $data): bool;

    public function delete(ParetoClassification $pareto): bool;
}
