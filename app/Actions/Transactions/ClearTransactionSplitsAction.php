<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Deshace el reparto y deja la transacción con una categoría —o sin ninguna—.
 *
 * Es el camino explícito que exige reemplazar un reparto por una sola
 * categoría. El endpoint de actualización normal rechaza esa escritura mientras
 * los splits existan, para que nadie borre un reparto sin saberlo.
 */
final class ClearTransactionSplitsAction
{
    public function __construct(
        private readonly TransactionRepositoryContract $repository,
    ) {}

    public function execute(int $transactionId, int $userId, ?int $categoryId): Transaction
    {
        $transaction = $this->repository->findById($transactionId, $userId);

        if (! $transaction) {
            throw new RuntimeException('Transaction not found');
        }

        if ($transaction->matched_transaction_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'Un movimiento conciliado no se reparte: el movimiento visible es el maestro.',
            ]);
        }

        $this->repository->clearSplits($transaction, $categoryId);

        return $transaction->fresh(['splits.category', 'detail']);
    }
}
