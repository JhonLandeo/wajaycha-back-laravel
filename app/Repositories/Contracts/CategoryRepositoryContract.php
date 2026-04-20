<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryRepositoryContract
{
    public function findById(int $id): ?Category;

    public function findByUserId(int $userId): Collection;

    public function getMonthlyReport(int $userId, int $month, int $year, int $page, int $perPage): LengthAwarePaginator;

    public function getAllForUser(int $userId): Collection;

    public function delete(Category $category): bool;

    public function update(Category $category, array $data): bool;

    public function create(array $data): Category;
}
