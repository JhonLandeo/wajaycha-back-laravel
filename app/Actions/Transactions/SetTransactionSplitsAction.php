<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\DTOs\Transactions\SetTransactionSplitsDTO;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reemplaza el reparto de una transacción por el conjunto recibido.
 *
 * Es idempotente: el cuerpo manda, no se suma a lo anterior. La transacción
 * queda con `category_id = null`, y la suma de las líneas tiene que ser
 * exactamente su monto. Los dos invariantes también viven en triggers de la
 * base; acá se validan antes para responder 422 en vez de un error de SQL.
 */
final class SetTransactionSplitsAction
{
    public function __construct(
        private readonly TransactionRepositoryContract $repository,
    ) {}

    public function execute(SetTransactionSplitsDTO $dto): Transaction
    {
        $transaction = $this->repository->findById($dto->transactionId, $dto->userId);

        if (! $transaction) {
            throw new RuntimeException('Transaction not found');
        }

        if ($transaction->matched_transaction_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'Un movimiento conciliado no se reparte: el movimiento visible es el maestro.',
            ]);
        }

        if (count($dto->splits) < 2) {
            throw ValidationException::withMessages([
                'splits' => 'El reparto debe tener al menos dos categorías.',
            ]);
        }

        $categoryIds = array_map(static fn ($line): int => $line->categoryId, $dto->splits);

        if (count($categoryIds) !== count(array_unique($categoryIds))) {
            throw ValidationException::withMessages([
                'splits' => 'No repitas una categoría en el mismo reparto.',
            ]);
        }

        $splitCents = array_sum(array_map(static fn ($line): int => $line->amountInCents(), $dto->splits));
        $transactionCents = (int) round((float) $transaction->amount * 100);

        if ($splitCents !== $transactionCents) {
            throw ValidationException::withMessages([
                'splits' => 'La suma del reparto debe ser exactamente igual al monto del movimiento.',
            ]);
        }

        $this->repository->replaceSplits($transaction, array_map(
            static fn ($line): array => [
                'category_id' => $line->categoryId,
                'amount' => $line->amount,
            ],
            $dto->splits,
        ));

        return $transaction->fresh(['splits.category', 'detail']);
    }
}
