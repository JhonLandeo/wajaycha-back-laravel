<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\StoreConsumptionHabitAction;
use App\Actions\Shopping\UpdateConsumptionHabitAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StoreConsumptionHabitRequest;
use App\Http\Requests\Shopping\UpdateConsumptionHabitRequest;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class ConsumptionHabitController extends Controller
{
    public function __construct(
        private readonly ShoppingRepositoryContract $repository,
        private readonly StoreConsumptionHabitAction $storeAction,
        private readonly UpdateConsumptionHabitAction $updateAction,
    ) {}

    public function index(): JsonResponse
    {
        $habits = $this->repository->activeHabitsFor((int) Auth::id());

        return response()->json(['data' => $habits]);
    }

    public function store(StoreConsumptionHabitRequest $request): JsonResponse
    {
        $habit = $this->storeAction->execute((int) Auth::id(), $request->validated());

        return response()->json(['data' => $habit], 201);
    }

    public function update(UpdateConsumptionHabitRequest $request, int $consumptionHabit): JsonResponse
    {
        $habit = $this->repository->findConsumptionHabit($consumptionHabit, (int) Auth::id());
        if ($habit === null) {
            return response()->json(['message' => 'Habito de consumo no encontrado'], 404);
        }

        $updated = $this->updateAction->execute($habit, $request->validated());

        return response()->json(['data' => $updated]);
    }

    public function destroy(int $consumptionHabit): JsonResponse
    {
        $habit = $this->repository->findConsumptionHabit($consumptionHabit, (int) Auth::id());
        if ($habit === null) {
            return response()->json(['message' => 'Habito de consumo no encontrado'], 404);
        }

        $this->repository->deleteConsumptionHabit($habit);

        return response()->json(['status' => 'deleted']);
    }
}
