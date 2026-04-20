<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Pareto\StoreParetoAction;
use App\Actions\Pareto\UpdateParetoAction;
use App\DTOs\Pareto\ParetoClassificationDTO;
use App\Http\Requests\ParetoClassification\StoreParetoClassificationRequest;
use App\Http\Requests\ParetoClassification\UpdateParetoClassificationRequest;
use App\Models\ParetoClassification;
use App\Repositories\Contracts\ParetoRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ParetoClassificationController extends Controller
{
    public function __construct(
        private readonly ParetoRepositoryContract $repository,
        private readonly StoreParetoAction $storeAction,
        private readonly UpdateParetoAction $updateAction
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $year = $request->input('year') ? (int) $request->input('year') : null;
        $userId = (int) Auth::id();

        $paginator = $this->repository->getMonthlyReport($userId, $month, $year, $page, $perPage);

        return response()->json($paginator);
    }

    /**
     * Display a listing of all resources.
     */
    public function all(): JsonResponse
    {
        $userId = (int) Auth::id();
        $data = $this->repository->getAllForUser($userId);
        return response()->json($data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreParetoClassificationRequest $request): JsonResponse
    {
        $dto = ParetoClassificationDTO::fromStoreRequest($request, (int) Auth::id());
        $data = $this->storeAction->execute($dto);
        return response()->json($data);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): JsonResponse
    {
        $pareto = $this->repository->findById($id);
        if (!$pareto) {
            return response()->json(['message' => 'Clasificación Pareto no encontrada'], 404);
        }
        return response()->json($pareto);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateParetoClassificationRequest $request, int $id): JsonResponse
    {
        $pareto = $this->repository->findById($id);
        if (!$pareto) {
            return response()->json(['message' => 'Clasificación Pareto no encontrada'], 404);
        }

        $dto = ParetoClassificationDTO::fromUpdateRequest($request, (int) Auth::id(), $id);
        $this->updateAction->execute($pareto, $dto);

        return response()->json($pareto->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $pareto = $this->repository->findById($id);
        if (!$pareto) {
            return response()->json(['message' => 'Clasificación Pareto no encontrada'], 404);
        }

        if ($this->repository->getCategories($id)->isNotEmpty()) {
            return response()->json([
                'message' => 'No se puede eliminar porque ya se está haciendo uso de estos.',
            ], 422);
        }

        $this->repository->delete($pareto);
        return response()->json(['status' => 'deleted']);
    }

    /**
     * Get categories associated with a Pareto classification.
     */
    public function categories(int $id): JsonResponse
    {
        $categories = $this->repository->getCategories($id);
        return response()->json($categories);
    }
}
