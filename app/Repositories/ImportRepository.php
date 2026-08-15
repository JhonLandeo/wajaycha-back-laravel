<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\PaymentService;
use App\Repositories\Contracts\ImportRepositoryContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ImportRepository implements ImportRepositoryContract
{
    /**
     * @return LengthAwarePaginator<int, Import>
     */
    public function paginateForUser(int $userId, int $perPage, int $page): LengthAwarePaginator
    {
        return Import::query()
            ->select([
                'id',
                'name',
                'financial_entity_id',
                'payment_service_id',
                'status',
                'extension',
                'created_at',
            ])
            ->with([
                'financialEntity:id,name',
                'paymentService:id,name',
            ])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findOwned(int $id, int $userId): ?Import
    {
        /** @var Import|null */
        return Import::query()
            ->whereKey($id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Import $import, array $data): bool
    {
        return $import->update($data);
    }

    public function delete(Import $import): bool
    {
        return (bool) $import->delete();
    }

    public function financialEntityCode(int $financialEntityId): ?string
    {
        /** @var string|null */
        return FinancialEntity::query()->whereKey($financialEntityId)->value('code');
    }

    /**
     * @return Collection<int, FinancialEntity>
     */
    public function financialEntities(): Collection
    {
        return FinancialEntity::query()->get();
    }

    /**
     * @return Collection<int, PaymentService>
     */
    public function paymentServices(): Collection
    {
        return PaymentService::query()->get();
    }
}
