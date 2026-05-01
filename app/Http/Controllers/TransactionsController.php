<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Transactions\StoreTransactionAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\DTOs\Transactions\TransactionDataDTO;
use App\DTOs\Transactions\TransactionFilterDTO;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class TransactionsController extends Controller
{
    public function __construct(
        private readonly TransactionRepositoryContract $repository,
        private readonly StoreTransactionAction $storeAction,
        private readonly UpdateTransactionAction $updateAction
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = TransactionFilterDTO::fromRequest($request, (int) Auth::id());

        $paginator = $this->repository->findPaginated($filters);

        return response()->json($paginator);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load('detail');
        return response()->json($transaction);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $dto = TransactionDataDTO::fromStoreRequest($request, (int) Auth::id());
        $transaction = $this->storeAction->execute($dto);
        return response()->json($transaction, 201);
    }

    public function getSummaryByCategory(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $year = $request->input('year') ? (int) $request->input('year') : null;
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $type = (string) $request->input('type');
        $search = $request->input('search');
        $userId = (int) Auth::id();

        $results = $this->repository->summaryByCategory($userId, $year, $month, $type, $perPage, $page, $search);

        return response()->json($results);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTransactionRequest $request, int $id): JsonResponse
    {
        $transaction = $this->repository->findById($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        if ($transaction->user_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $dto = TransactionDataDTO::fromUpdateRequest($request, (int) Auth::id(), $id);
        $this->updateAction->execute($dto);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $transaction = $this->repository->findById($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        if (!$transaction->is_manual) {
            return response()->json(['message' => 'Solo las transacciones manuales pueden ser eliminadas.'], 403);
        }

        $this->repository->delete($transaction);
        return response()->json(['status' => 'deleted']);
    }
}
