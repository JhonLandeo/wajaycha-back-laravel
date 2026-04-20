<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\TransactionTag;
use App\Models\TransactionYape;
use App\Services\CategorizationService;
use App\Services\ClassificationService;
use Carbon\Carbon;

class UpdateTransactionAction
{
    public function __construct(
        protected CategorizationService $categorizationService,
        protected ClassificationService $classifier
    ) {}

    /**
     * @param int $userId
     * @param Transaction $transaction
     * @param array<string, mixed> $validatedData
     * @param array<string, mixed> $requestData
     * @return Transaction
     */
    public function execute(int $userId, Transaction $transaction, array $validatedData, array $requestData): Transaction
    {
        if (empty($validatedData['detail_id']) && !empty($requestData['detail_description'])) {
            $detail = Detail::firstOrCreate([
                'user_id' => $userId,
                'description' => $requestData['detail_description']
            ]);
            $validatedData['detail_id'] = $detail->id;
            GenerateEmbeddingForDetail::dispatch($detail, $requestData['category_id'] ?? null);
        }

        $transaction->update([
            'amount' => $validatedData['amount'] ?? $transaction->amount,
            'date_operation' => $validatedData['date_operation'] ?? $transaction->date_operation,
            'type_transaction' => $validatedData['type_transaction'] ?? $transaction->type_transaction,
            'detail_id' => $validatedData['detail_id'] ?? $transaction->detail_id,
            'category_id' => $validatedData['category_id'] ?? $transaction->category_id,
        ]);

        $newCategoryId = (int)($requestData['category_id'] ?? $transaction->category_id);

        if (!empty($requestData['is_frequent'])) {
            $this->updateTransactionFrequent($userId, $requestData, $newCategoryId);
        } else {
            $this->updateTransactionWithoutFrequent($userId, $requestData, $newCategoryId);
        }
        
        return $transaction;
    }

    /**
     * @param int $userId
     * @param array<string, mixed> $requestData
     * @param int $newCategoryId
     * @return void
     */
    private function updateTransactionFrequent(int $userId, array $requestData, int $newCategoryId): void
    {
        if (($requestData['source_type'] ?? '') == 'yape_unmatched') {
            $yapeTransaction = TransactionYape::find($requestData['transaction_id'] ?? 0);
            if ($yapeTransaction) {
                $yapeTransaction->category_id = $newCategoryId;
                $yapeTransaction->save();

                if (($requestData['reason'] ?? '') === 'with_reason' && !empty($requestData['tag_id'])) {
                    $transactionTag = new TransactionTag();
                    $transactionTag->transaction_yape_id = $yapeTransaction->id;
                    $transactionTag->tag_id = $requestData['tag_id'];
                    $transactionTag->save();
                }

                $yapeTransaction->load('detail');
                $detail = $yapeTransaction->detail;
                if ($detail) {
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
            $transaction = Transaction::find($requestData['transaction_id'] ?? 0);
            if ($transaction) {
                $transaction->category_id = $newCategoryId;
                $transaction->save();
                
                $transaction->load('detail');
                $detail = $transaction->detail;

                if ($detail) {
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

    /**
     * @param int $userId
     * @param array<string, mixed> $requestData
     * @param int $newCategoryId
     * @return void
     */
    private function updateTransactionWithoutFrequent(int $userId, array $requestData, int $newCategoryId): void
    {
        if (($requestData['source_type'] ?? '') == 'yape_unmatched') {
            $yapeTransaction = TransactionYape::find($requestData['transaction_id'] ?? 0);
            if ($yapeTransaction) {
                $yapeTransaction->category_id = $newCategoryId;
                $yapeTransaction->save();
                if (($requestData['reason'] ?? '') === 'with_reason' && !empty($requestData['tag_id'])) {
                    $transactionTag = new TransactionTag();
                    $transactionTag->transaction_yape_id = $yapeTransaction->id;
                    $transactionTag->tag_id = $requestData['tag_id'];
                    $transactionTag->save();
                }
                $yapeTransaction->load('detail');
                $detail = $yapeTransaction->detail;
                if ($detail && $this->classifier->isDetailUsefulForLearning($detail->description)) {
                    GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
                }
            }
        } else {
            $transaction = Transaction::find($requestData['transaction_id'] ?? 0);
            if ($transaction) {
                $transaction->category_id = $newCategoryId;
                $transaction->save();
                $transaction->load('detail');
                $detail = $transaction->detail;
                
                $this->updateMatchingYapeTransaction($transaction, $userId, $newCategoryId);
                
                if ($detail && $this->classifier->isDetailUsefulForLearning($detail->description)) {
                    GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
                }
            }
        }
    }

    private function updateMatchingYapeTransaction(Transaction $transaction, int $userId, int $newCategoryId): void
    {
        $yapeTransaction = TransactionYape::where('amount', $transaction->amount)
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
