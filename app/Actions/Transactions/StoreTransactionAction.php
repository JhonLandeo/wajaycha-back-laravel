<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;

class StoreTransactionAction
{
    /**
     * @param int $userId
     * @param array<string, mixed> $validatedData
     * @param string|null $detailDescription
     * @param int|null $categoryId
     * @return Transaction
     */
    public function execute(int $userId, array $validatedData, ?string $detailDescription, ?int $categoryId): Transaction
    {
        $validatedData['user_id'] = $userId;

        if (empty($validatedData['detail_id']) && !empty($detailDescription)) {
            $detail = Detail::firstOrCreate([
                'user_id' => $userId,
                'description' => $detailDescription
            ]);
            $validatedData['detail_id'] = $detail->id;
            GenerateEmbeddingForDetail::dispatch($detail, $categoryId);
        }

        $validatedData['is_manual'] = true;

        return Transaction::create($validatedData);
    }
}
