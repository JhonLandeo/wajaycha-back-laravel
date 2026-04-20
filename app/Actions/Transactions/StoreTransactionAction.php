<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\DTOs\Transactions\TransactionDataDTO;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryContract;

final class StoreTransactionAction
{
    public function __construct(
        private readonly TransactionRepositoryContract $repository
    ) {
    }

    public function execute(TransactionDataDTO $dto): Transaction
    {
        $data = [
            'amount' => $dto->amount,
            'date_operation' => $dto->date_operation,
            'type_transaction' => $dto->type_transaction,
            'user_id' => $dto->user_id,
            'category_id' => $dto->category_id,
            'detail_id' => $dto->detail_id,
            'is_manual' => $dto->is_manual,
        ];

        if (empty($data['detail_id']) && !empty($dto->detail_description)) {
            /** @var Detail $detail */
            $detail = Detail::query()->firstOrCreate([
                'user_id' => $dto->user_id,
                'description' => $dto->detail_description
            ]);
            $data['detail_id'] = $detail->id;
            GenerateEmbeddingForDetail::dispatch($detail, $dto->category_id);
        }

        return $this->repository->create($data);
    }
}
