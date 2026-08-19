<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Categories\StoreCategoryAction;
use App\Actions\Categories\UpdateCategoryAction;
use App\DTOs\Categories\CategoryDataDTO;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryRepositoryContract $repository,
        private readonly StoreCategoryAction $storeAction,
        private readonly UpdateCategoryAction $updateAction
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);
        $month = (int) $request->input('month', date('m'));
        $year = (int) $request->input('year', date('Y'));
        $search = $request->input('search');
        $userId = (int) Auth::id();

        $paginator = $this->repository->getMonthlyReport($userId, $month, $year, $page, $perPage, $search);

        return response()->json($paginator);
    }

    /**
     * Display a listing of all resources for the authenticated user.
     */
    public function all(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        $search = $request->input('search');
        $categories = $this->repository->getAllForUser($userId, $search);

        return response()->json($categories);
    }

    public function show(int $id): JsonResponse
    {
        $category = $this->repository->findById($id, (int) Auth::id());
        if (!$category) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        return response()->json($this->withParetoClassification($category));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $dto = CategoryDataDTO::fromStoreRequest($request, (int) Auth::id());
        $category = $this->storeAction->execute($dto);

        return response()->json($this->withParetoClassification($category), 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $category = $this->repository->findById($id, (int) Auth::id());
        if (!$category) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        $dto = CategoryDataDTO::fromUpdateRequest($request, (int) Auth::id());
        $updatedCategory = $this->updateAction->execute($category, $dto);

        return response()->json($this->withParetoClassification($updatedCategory));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = $this->repository->findById($id, (int) Auth::id());
        if (!$category) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        if ($category->transactions()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la categoría porque tiene transacciones asociadas'
            ], 422);
        }

        $this->repository->delete($category);
        return response()->json(['status' => 'deleted']);
    }

    /**
     * Patch Pareto classification for a category.
     */
    public function patchPareto(int $id, Request $request): JsonResponse
    {
        $category = $this->repository->findById($id, (int) Auth::id());
        if (!$category) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        $request->validate([
            'pareto_classification_id' => 'sometimes|nullable|exists:pareto_classifications,id',
        ]);

        // Wrap in a DTO to reuse UpdateCategoryAction logic while keeping decoupling.
        // `parent_id` is deliberately left unset: this endpoint changes a band and
        // nothing else, and the DTO only reparents when the caller says so.
        $dto = new CategoryDataDTO(
            name: $category->name,
            type: $category->type,
            monthly_budget: (float) $category->monthly_budget,
            user_id: (int) $category->user_id,
            pareto_classification_id: $request->pareto_classification_id ? (int) $request->pareto_classification_id : null
        );

        $updated = $this->updateAction->execute($category, $dto);

        return response()->json($this->withParetoClassification($updated));
    }

    /**
     * A category as the SPA reads it: the row plus the band it is in.
     *
     * `categories.pareto_classification_id` was dropped when the link moved to
     * `category_pareto_assignments`, and every response that serialises a bare
     * `Category` lost the field with it. The clients never stopped sending it back on
     * save, so the write path kept working and only the read looked broken — an edit
     * form that opens with no band selected on a category that has one.
     *
     * @return array<string, mixed>
     */
    private function withParetoClassification(Category $category): array
    {
        return $category->toArray() + [
            'pareto_classification_id' => $this->repository->paretoClassificationIdFor((int) $category->id),
        ];
    }
}
