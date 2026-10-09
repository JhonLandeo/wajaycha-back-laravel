<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\StorePantryItemAction;
use App\Actions\Shopping\UpdatePantryItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StorePantryItemRequest;
use App\Http\Requests\Shopping\UpdatePantryItemRequest;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class PantryItemController extends Controller
{
    public function __construct(
        private readonly ShoppingRepositoryContract $repository,
        private readonly StorePantryItemAction $storeAction,
        private readonly UpdatePantryItemAction $updateAction,
    ) {}

    public function index(): JsonResponse
    {
        $items = $this->repository->listPantryItemsFor((int) Auth::id());

        return response()->json(['data' => $items]);
    }

    public function store(StorePantryItemRequest $request): JsonResponse
    {
        $item = $this->storeAction->execute((int) Auth::id(), $request->validated());

        return response()->json(['data' => $item], 201);
    }

    public function update(UpdatePantryItemRequest $request, int $pantryItem): JsonResponse
    {
        $item = $this->repository->findPantryItem($pantryItem, (int) Auth::id());
        if ($item === null) {
            return response()->json(['message' => 'Item de despensa no encontrado'], 404);
        }

        $updated = $this->updateAction->execute($item, $request->validated());

        return response()->json(['data' => $updated]);
    }

    public function destroy(int $pantryItem): JsonResponse
    {
        $item = $this->repository->findPantryItem($pantryItem, (int) Auth::id());
        if ($item === null) {
            return response()->json(['message' => 'Item de despensa no encontrado'], 404);
        }

        $this->repository->deletePantryItem($item);

        return response()->json(['status' => 'deleted']);
    }
}
