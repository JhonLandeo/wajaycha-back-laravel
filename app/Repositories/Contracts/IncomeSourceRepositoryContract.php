<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\IncomeSource;
use App\Models\IncomeSourceVersion;
use Illuminate\Database\Eloquent\Collection;

interface IncomeSourceRepositoryContract
{
    /** @return Collection<int, IncomeSource> */
    public function allForUser(int $userId): Collection;

    public function findForUser(int $id, int $userId): ?IncomeSource;

    /**
     * @param  array{user_id: int, name: string, kind: string}  $attributes
     */
    public function create(array $attributes): IncomeSource;

    /**
     * @param  array{name: string, kind: string}  $attributes
     */
    public function update(IncomeSource $source, array $attributes): IncomeSource;

    public function delete(IncomeSource $source): void;

    /**
     * @param  array{amount: string, effective_from: string, effective_to: string|null}  $attributes
     */
    public function addVersion(IncomeSource $source, array $attributes): IncomeSourceVersion;

    public function openVersion(IncomeSource $source): ?IncomeSourceVersion;

    public function latestVersion(IncomeSource $source): ?IncomeSourceVersion;

    public function closeOpenVersion(IncomeSource $source, string $effectiveTo): void;

    /** @return Collection<int, IncomeSourceVersion> */
    public function versionsForUser(int $userId): Collection;
}
