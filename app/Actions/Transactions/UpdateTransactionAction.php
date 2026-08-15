<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\DTOs\Transactions\TransactionDataDTO;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\TransactionTag;
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
    ) {}

    public function execute(TransactionDataDTO $dto): Transaction
    {
        $transaction = $this->repository->findById($dto->transaction_id ?? 0, $dto->user_id);
        if (! $transaction) {
            throw new \RuntimeException('Transaction not found');
        }

        $updateData = [];

        // Monto, fecha, tipo y comercio de un movimiento importado son el
        // registro del banco, no del usuario: reescribirlos desde una peticion
        // HTTP falsea el asiento. La categoria no es eso — es la clasificacion
        // que hace el usuario, y es el unico campo aqui que el banco nunca
        // proveyo.
        //
        // La distincion vive en la accion y no en el controlador a proposito.
        // El controlador solo sabria confiar en el payload; aqui se decide sobre
        // la fila ya cargada, asi que un cliente que mande un monto distinto
        // sobre un movimiento importado no lo escribe, en vez de que lo escriba
        // si el guard de arriba se olvida.
        if ($transaction->is_manual) {
            $updateData = [
                'amount' => $dto->amount,
                'date_operation' => $dto->date_operation,
                'type_transaction' => $dto->type_transaction,
            ];

            if ($dto->detail_id !== null) {
                $updateData['detail_id'] = $dto->detail_id;
            }

            if (empty($dto->detail_id) && ! empty($dto->detail_description)) {
                /** @var Detail $detail */
                $detail = Detail::query()->firstOrCreate([
                    'user_id' => $dto->user_id,
                    'description' => $dto->detail_description,
                ]);
                $updateData['detail_id'] = $detail->id;
                GenerateEmbeddingForDetail::dispatch($detail, $dto->category_id);
            }
        }

        if ($dto->category_id !== null) {
            $updateData['category_id'] = $dto->category_id;
        }

        if ($updateData !== []) {
            $this->repository->update($transaction, $updateData);
        }

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
        $transaction = $this->repository->findById($dto->transaction_id ?? 0, $dto->user_id);
        if ($transaction) {
            $transaction->category_id = $newCategoryId;
            $transaction->save();

            if ($dto->reason === 'with_reason' && $dto->tag_id) {
                $transactionTag = new TransactionTag;
                $transactionTag->transaction_id = $transaction->id;
                $transactionTag->tag_id = $dto->tag_id;
                $transactionTag->save();
            }

            $transaction->load('detail');
            $detail = $transaction->detail;
            if ($detail && $newCategoryId) {
                // El filtro por usuario no es defensa en profundidad, es la
                // condicion que faltaba: el join es por `d.description`, no por
                // `d.id`, asi que sin el alcanza los movimientos de CUALQUIER
                // usuario que le haya puesto el mismo nombre al comercio —
                // "RAPPI", "YAPE"— y les asigna una categoria que no es suya.
                //
                // Hoy eso no corrompe nada porque el FK compuesto
                // `fk_transactions_category_id (category_id, user_id)` lo
                // rechaza, pero entonces la peticion revienta con un 500 en vez
                // de categorizar. Estuvo latente mientras editar un movimiento
                // importado era imposible; abrir esa puerta lo vuelve el camino
                // normal.
                Transaction::query()
                    ->join('details as d', 'transactions.detail_id', '=', 'd.id')
                    ->where('transactions.user_id', $transaction->user_id)
                    ->where('d.description', $detail->description)
                    ->whereNull('transactions.category_id')
                    ->update(['category_id' => $newCategoryId]);

                $this->categorizationService->setRule(
                    $transaction->user_id,
                    $detail->id,
                    $newCategoryId
                );
            }
        }
    }

    private function updateTransactionWithoutFrequent(TransactionDataDTO $dto, ?int $newCategoryId): void
    {
        $transaction = $this->repository->findById($dto->transaction_id ?? 0, $dto->user_id);
        if ($transaction) {
            $transaction->category_id = $newCategoryId;
            $transaction->save();

            if ($dto->reason === 'with_reason' && $dto->tag_id) {
                $transactionTag = new TransactionTag;
                $transactionTag->transaction_id = $transaction->id;
                $transactionTag->tag_id = $dto->tag_id;
                $transactionTag->save();
            }

            $transaction->load('detail');
            $detail = $transaction->detail;

            // Search for other transactions (like Yape imports) that might match this one to update their category too
            $this->updateMatchingOtherTransactions($transaction, $dto->user_id, $newCategoryId);

            if ($detail && $newCategoryId && $this->classifier->isDetailUsefulForLearning($detail->description)) {
                GenerateEmbeddingForDetail::dispatch($detail, $newCategoryId);
            }
        }
    }

    private function updateMatchingOtherTransactions(Transaction $transaction, int $userId, ?int $newCategoryId): void
    {
        // Now that everything is in the same table, we look for other transactions
        // with the same amount/date but different source_type
        Transaction::query()
            ->where('amount', $transaction->amount)
            ->where('user_id', $userId)
            ->where('type_transaction', $transaction->type_transaction)
            ->whereDate('date_operation', Carbon::parse($transaction->date_operation)->toDateString())
            ->where('id', '!=', $transaction->id)
            ->update(['category_id' => $newCategoryId]);
    }
}
