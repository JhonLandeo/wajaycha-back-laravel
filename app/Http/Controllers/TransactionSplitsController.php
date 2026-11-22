<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Transactions\ClearTransactionSplitsAction;
use App\Actions\Transactions\SetTransactionSplitsAction;
use App\DTOs\Transactions\SetTransactionSplitsDTO;
use App\Http\Requests\Transaction\ClearTransactionSplitsRequest;
use App\Http\Requests\Transaction\SetTransactionSplitsRequest;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * El reparto por categoría de un movimiento.
 *
 * Las dos rutas resuelven la transacción por el repositorio scoped al usuario
 * autenticado: un id ajeno es 404, igual que en `TransactionsController`. El
 * reparto es una edición de la clasificación, no del asiento del banco.
 */
final class TransactionSplitsController extends Controller
{
    public function __construct(
        private readonly TransactionRepositoryContract $repository,
        private readonly SetTransactionSplitsAction $setAction,
        private readonly ClearTransactionSplitsAction $clearAction,
    ) {}

    public function update(SetTransactionSplitsRequest $request, int $transaction): JsonResponse
    {
        if (! $this->repository->findById($transaction, (int) Auth::id())) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        $dto = SetTransactionSplitsDTO::fromRequest($request, (int) Auth::id(), $transaction);

        return response()->json($this->setAction->execute($dto));
    }

    public function destroy(ClearTransactionSplitsRequest $request, int $transaction): JsonResponse
    {
        if (! $this->repository->findById($transaction, (int) Auth::id())) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        $categoryId = $request->validated('category_id');

        return response()->json($this->clearAction->execute(
            $transaction,
            (int) Auth::id(),
            $categoryId !== null ? (int) $categoryId : null,
        ));
    }
}
