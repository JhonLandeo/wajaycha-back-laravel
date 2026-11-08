<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\IncomeSource;
use App\Models\IncomeSourceVersion;
use App\Repositories\Contracts\IncomeSourceRepositoryContract;
use Illuminate\Database\Eloquent\Collection;

final class IncomeSourceRepository implements IncomeSourceRepositoryContract
{
    /** @return Collection<int, IncomeSource> */
    public function allForUser(int $userId): Collection
    {
        return IncomeSource::query()
            ->where('user_id', $userId)
            ->with(['versions'])
            ->orderBy('name')
            ->get();
    }

    public function findForUser(int $id, int $userId): ?IncomeSource
    {
        /** @var IncomeSource|null $source */
        $source = IncomeSource::query()
            ->whereKey($id)
            ->where('user_id', $userId)
            ->first();

        return $source;
    }

    /**
     * @param  array{user_id: int, name: string, kind: string}  $attributes
     */
    public function create(array $attributes): IncomeSource
    {
        /** @var IncomeSource $source */
        $source = IncomeSource::query()->create($attributes);

        return $source;
    }

    /**
     * @param  array{name: string, kind: string}  $attributes
     */
    public function update(IncomeSource $source, array $attributes): IncomeSource
    {
        $source->update($attributes);

        return $source->refresh();
    }

    public function delete(IncomeSource $source): void
    {
        $source->delete();
    }

    /**
     * @param  array{amount: string, effective_from: string, effective_to: string|null}  $attributes
     */
    public function addVersion(IncomeSource $source, array $attributes): IncomeSourceVersion
    {
        /** @var IncomeSourceVersion $version */
        $version = $source->versions()->create($attributes);

        return $version;
    }

    public function openVersion(IncomeSource $source): ?IncomeSourceVersion
    {
        /** @var IncomeSourceVersion|null $version */
        $version = $source->versions()
            ->whereNull('effective_to')
            ->orderByDesc('effective_from')
            ->first();

        return $version;
    }

    public function latestVersion(IncomeSource $source): ?IncomeSourceVersion
    {
        /** @var IncomeSourceVersion|null $version */
        $version = $source->versions()
            ->orderByDesc('effective_from')
            ->first();

        return $version;
    }

    public function closeOpenVersion(IncomeSource $source, string $effectiveTo): void
    {
        $source->versions()
            ->whereNull('effective_to')
            ->update(['effective_to' => $effectiveTo, 'updated_at' => now()]);
    }

    /** @return Collection<int, IncomeSourceVersion> */
    public function versionsForUser(int $userId): Collection
    {
        return IncomeSourceVersion::query()
            ->with('source')
            ->whereHas('source', static fn ($query) => $query->where('user_id', $userId))
            ->get();
    }
}
