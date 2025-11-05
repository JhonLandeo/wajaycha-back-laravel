<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Detail;
use App\Models\CategorizationRule;
use App\Services\CategorizationService;
use App\Jobs\GenerateEmbeddingForDetail;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryRuleController extends Controller
{
    public function getRules(Request $request, Category $category): JsonResponse
    {
        $per_page = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $userId = Auth::id();
        $rules = Detail::query()
            ->join('categorization_rules as cr', 'details.id', '=', 'cr.detail_id')
            ->where('cr.category_id', $category->id)
            ->where('cr.user_id', $userId)
            ->select('details.id', 'details.description')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json($rules);
    }

    public function getSuggestions(Request $request, Category $category): JsonResponse
    {
        $userId = Auth::id();
        $per_page = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        Log::info('userId: ' . $userId . ', categoryId: ' . $category->id);
        $existingDetailIds = CategorizationRule::where('user_id', $userId)
            ->where('category_id', $category->id)
            ->pluck('detail_id');
        Log::info('existingDetailIds: ' . var_export($existingDetailIds, true));

        $centroidVector = Detail::query()
            ->where('user_id', $userId)
            ->where('last_used_category_id', $category->id)
            ->whereNotNull('embedding')
            ->avg('embedding');

        if (!$centroidVector) {
            return response()->json(Detail::whereRaw('1=0')->paginate(25));
        }

        $suggestions = Detail::query()
            ->where('user_id', $userId)
            ->whereNull('last_used_category_id')
            ->whereNotNull('embedding')
            ->whereNotIn('id', $existingDetailIds)
            ->orderByRaw('embedding <=> ?', [$centroidVector])
            ->limit(100)
            ->select('id', 'description')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json($suggestions);
    }

    public function syncRule(Request $request, Category $category, CategorizationService $categorizationService): JsonResponse
    {
        $userId = Auth::id();
        $request->validate([
            'detail_id' => 'required|integer|exists:details,id',
        ]);
        $detailIdToSync = $request->input('detail_id');
        $categorizationService->createExactRule($userId, $detailIdToSync, $category->id);
        $detail = Detail::find($detailIdToSync);

        if ($detail) {
            GenerateEmbeddingForDetail::dispatch($detail, $category->id);
        }

        return response()->json(['status' => 'ok', 'message' => 'Reglas actualizadas']);
    }
}
