<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Transactions\TransactionFilterDTO;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;

interface TransactionRepositoryContract
{
    /**
     * @param TransactionFilterDTO $filters
     * @return LengthAwarePaginatorContract<int, \stdClass>
     */
    public function findPaginated(TransactionFilterDTO $filters): LengthAwarePaginatorContract;
    
    /**
     * @param int $userId
     * @param int|null $year
     * @param int|null $month
     * @param string|null $type
     * @param int $perPage
     * @param int $page
     * @param string|null $search
     * @return LengthAwarePaginatorContract<int, \App\Models\Transaction>
     */
    public function summaryByCategory(int $userId, ?int $year, ?int $month, ?string $type, int $perPage, int $page, ?string $search = null): LengthAwarePaginatorContract;

    public function findById(int $id): ?Transaction;

    public function create(array $data): Transaction;

    public function update(Transaction $transaction, array $data): bool;

    public function delete(Transaction $transaction): bool;
}
