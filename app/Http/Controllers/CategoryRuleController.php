<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRule\SyncRuleRequest;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Category;
use App\Models\Detail;
use App\Repositories\Contracts\CategoryRepositoryContract;
use App\Repositories\Contracts\DetailRepositoryContract;
use App\Services\CategorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The rules that decide how a movement gets categorised. Orchestrates; decides nothing.
 */
class CategoryRuleController extends Controller
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly DetailRepositoryContract $details,
    ) {}

    /**
     * Resolve a Category owned by the caller.
     *
     * Route-model binding matched on the primary key alone, so these endpoints accepted
     * any category id in the system.
     */
    private function ownedCategory(int $id): ?Category
    {
        return $this->categories->findById($id, (int) Auth::id());
    }

    public function getRules(Request $request, int $categoryId): JsonResponse
    {
        $category = $this->ownedCategory($categoryId);
        if (! $category) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        return response()->json($this->details->paginateRulesForCategory(
            (int) Auth::id(),
            $category->id,
            (int) $request->input('per_page', 10),
            (int) $request->input('page', 1),
        ));
    }

    public function getSuggestions(Request $request, int $categoryId): JsonResponse
    {
        $category = $this->ownedCategory($categoryId);
        if (! $category) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        return response()->json($this->details->paginateSuggestionsForCategory(
            (int) Auth::id(),
            $category->id,
            (int) $request->input('per_page', 10),
            (int) $request->input('page', 1),
        ));
    }

    public function syncRule(
        SyncRuleRequest $request,
        int $categoryId,
        CategorizationService $categorizationService
    ): JsonResponse {
        $category = $this->ownedCategory($categoryId);
        if (! $category) {
            return response()->json(['message' => 'Categoría no encontrada'], 404);
        }

        $userId = (int) Auth::id();
        $detailId = (int) $request->input('detail_id');

        $categorizationService->setRule($userId, $detailId, $category->id);

        // Scoped, unlike the `Detail::find()` this replaces. `SyncRuleRequest` already
        // rejects another user's id, so this changes nothing today — but a lookup that
        // depends on validation elsewhere to be safe is one edit away from not being.
        $detail = Detail::query()
            ->whereKey($detailId)
            ->where('user_id', $userId)
            ->first();

        if ($detail) {
            GenerateEmbeddingForDetail::dispatch($detail, $category->id);
        }

        return response()->json(['status' => 'ok', 'message' => 'Reglas actualizadas']);
    }
}
