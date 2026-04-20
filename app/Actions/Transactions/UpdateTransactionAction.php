<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\DTOs\Transactions\TransactionDataDTO;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\TransactionTag;
use App\Models\TransactionYape;
use App\Repositories\Contracts\TransactionRepositoryContract;
use App\Services\CategorizationService;
use App\Services\ClassificationService;
use Carbon\Carbon;

final class UpdateTransactionAction
{
    public function __construct(
        private readonly TransactionRepositoryContract $repository,
        private readonly CategorizationService $categorizationService,
        private readonly ClassificationService $classifier
    ) {
    }

    public function execute(TransactionDataDTO $dto): Transaction
    {
        $transaction = $this->repository->findById($dto->transaction_id ?? 0);
        if (!$transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        $updateData = [
            'amount'           => $dto->amount,
            'date_operation'   => $dto->date_operation,
            'type_transaction' => $dto->type_transaction,
        ];

        if ($dto->category_id !== null) {
            $updateData['category_id'] = $dto->category_id;
        }

        if ($dto->detail_id !== null) {
            $updateData['detail_id'] = $dto->detail_id;
        }

        if (empty($dto->detail_id) && !empty($dto->detail_description)) {
            /** @var Detail $detail */
            $detail = Detail::query()->firstOrCreate([
                'user_id' => $dto->user_id,
                'description' => $dto->detail_description
            ]);
            $updateData['detail_id'] = $detail->id;
            GenerateEmbeddingForDetail::dispatch($detail, $dto->category_id);
        }

        $this->repository->update($transaction, $updateData);

        $newCategoryId = $dto->category_id ?? $transaction->category_id;

        if ($dto->is_frequent) {
            $this->updateTransactionFrequent($dto, $newCategoryId);
        } else {
            $this->updateTransactionWithoutFrequent($dto, $newCategoryId);
        }

        return $transaction->fresh();
    }

    private function updateTransactionFrequent(TransactionDataDTO $dto, ?int $newCategoryId): void
    {
        if ($dto->source_type === 'yape_unmatched') {
            /** @var TransactionYape|null $yapeTransaction */
            $yapeTransaction = TransactionYape::query()->find($dto->transaction_id ?? 0);
            if ($yapeTransaction) {
                $yapeTransaction->category_id = $newCategoryId;
                $yapeTransaction->save();

                if ($dto->reason === 'with_reason' && $dto->tag_id) {
                    $transactionTag = new TransactionTag();
                    $transactionTag->transaction_yape_id = $yapeTransaction->id;
                    $transactionTag->tag_id = $dto->tag_id;
                    $transactionTag->save();
                }

                $yapeTransaction->load('detail');
                $detail = $yapeTransaction->detail;
                if ($detail && $newCategoryId) {
                    TransactionYape::query()
                        ->join('details as d', 'transaction_yapes.detail_id', '=', 'd.id')
                        ->where('d.description', $detail->description)
                        ->whereNull('transaction_yapes.category_id')
                        ->update(['category_id' => $newCategoryId]);

                    $this->categorizationService->createExactRule(
                        $yapeTransaction->user_id,
                        $detail->id,
                        $newCategoryId
                    );
                }
            }
        } else {
            $transaction = $this->repository->findById($dto->transaction_id ?? 0);
            if ($transaction) {
                $transaction->category_id = $newCategoryId;
                $transaction->save();

                $transaction->load('detail');
                $detail = $transaction->detail;

                if ($detail && $newCategoryId) {
                    Transaction::query()
                        ->join('details as d', 'transactions.detail_id', '=', 'd.id')
                        ->where('d.description', $detail->description)
                        ->whereNull('transactions.category_id')
                        ->update(['category_id' => $newCategoryId]);

                    $this->categorizationService->createExactRule(
                        $transaction->user_id,
                        $detail->id,
                        $newCategoryId
                    );
                }
            }
        }
    }

    private function updateTransactionWithoutFrequent(TransactionDataDTO $dto, ?int $newCategoryId): void
    {
        if ($dto->source_type === 'yape_unmatched') {
            /** @var TransactionYape|null $yapeTransaction */
            $yapeTransaction = TransactionYape::query()->find($dto->transaction_id ?? 0);
            if ($yapeTransaction) {
                $yapeTransaction->category_id = $newCategoryId;
                $yapeTransaction->save();
                if ($dto->reason === 'with_reason' && $dto->tag_id) {
                    $transactionTag = new TransactionTag();
                    $transactionTag->transaction_yape_id = $yapeTransaction->id;
                    $transactionTag->tag_id = $dto->tag_id;
                    $transactionTag->save();
                }
                $yapeTransaction->load('detail');
                $detail = $yapeTransaction->detail;
                if ($detail && $newCategoryId && $this->classifier->isDetailUsefulForLearning($detail->description)) {
                    GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
                }
            }
        } else {
            $transaction = $this->repository->findById($dto->transaction_id ?? 0);
            if ($transaction) {
                $transaction->category_id = $newCategoryId;
                $transaction->save();
                $transaction->load('detail');
                $detail = $transaction->detail;

                $this->updateMatchingYapeTransaction($transaction, $dto->user_id, $newCategoryId);

                if ($detail && $newCategoryId && $this->classifier->isDetailUsefulForLearning($detail->description)) {
                    GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
                }
            }
        }
    }

    private function updateMatchingYapeTransaction(Transaction $transaction, int $userId, ?int $newCategoryId): void
    {
        $yapeTransaction = TransactionYape::query()
            ->where('amount', $transaction->amount)
            ->where('user_id', $userId)
            ->where('type_transaction', $transaction->type_transaction)
            ->whereDate('date_operation', Carbon::parse($transaction->date_operation)->toDateString())
            ->first();

        if ($yapeTransaction) {
            $yapeTransaction->category_id = $newCategoryId;
            $yapeTransaction->save();
        }
    }
}
