<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Detail;
use App\Models\CategorizationRule;
use App\Services\CategorizationService;
use App\Jobs\GenerateEmbeddingForDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CategoryRuleController extends Controller
{
    /**
     * 2. ¡NUEVO! Obtiene la lista paginada de reglas existentes.
     * (Responde a GET /categories/{category}/rules)
     */
    public function getRules(Request $request, Category $category)
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

    /**
     * 3. ¡NUEVO! Obtiene la lista paginada de sugerencias.
     * (Responde a GET /categories/{category}/suggestions)
     */
    public function getSuggestions(Request $request, Category $category)
    {
        $userId = Auth::id();
        $per_page = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        // 1. Necesitamos saber qué detalles YA son reglas
        //    para poder excluirlos de las sugerencias.
        $existingDetailIds = CategorizationRule::where('user_id', $userId)
            ->where('category_id', $category->id)
            ->pluck('detail_id');

        // 2. Calculamos el "Vector Centroide"
        $centroidVector = Detail::query()
            ->where('user_id', $userId)
            ->where('last_used_category_id', $category->id)
            ->whereNotNull('embedding')
            ->avg('embedding');

        // 3. Si no hay vector (categoría no "entrenada"),
        //    devolvemos un paginador vacío.
        if (!$centroidVector) {
            // Un truco limpio para devolver un Paginator vacío
            return response()->json(Detail::whereRaw('1=0')->paginate(25));
        }

        // 4. Buscamos detalles "huérfanos" y los paginamos
        $suggestions = Detail::query()
            ->where('user_id', $userId)
            ->whereNull('last_used_category_id') // Huérfanos
            ->whereNotNull('embedding')
            ->whereNotIn('id', $existingDetailIds) // Excluir reglas existentes
            ->orderByRaw('embedding <=> ?', [$centroidVector])
            ->limit(100) // Optimizacion: solo buscar en los 100 más cercanos
            ->select('id', 'description')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json($suggestions);
    }

    /**
     * 4. Guarda los cambios del modal (sincroniza las reglas).
     * (Responde a POST /categories/{category}/sync)
     *
     * ¡IMPORTANTE! Esta lógica no cambia.
     * Tu frontend es responsable de mantener un array con
     * TODOS los IDs seleccionados (de todas las páginas)
     * y enviarlo aquí.
     */
    public function syncRules(Request $request, Category $category, CategorizationService $categorizationService)
    {
        $userId = Auth::id();

        $request->validate([
            'detail_ids' => 'required|array',
            'detail_ids.*' => 'integer|exists:details,id',
        ]);

        $detailIdsToSync = $request->input('detail_ids');

        $currentRules = CategorizationRule::where('user_id', $userId)
            ->where('category_id', $category->id)
            ->pluck('detail_id')
            ->all();

        // --- Sincronización ---
        $detailsToAdd = array_diff($detailIdsToSync, $currentRules);
        foreach ($detailsToAdd as $detailId) {
            $categorizationService->createExactRule($userId, $detailId, $category->id);

            $detail = Detail::find($detailId);
            if ($detail) {
                GenerateEmbeddingForDetail::dispatch($detail, $category->id);
            }
        }

        $detailsToRemove = array_diff($currentRules, $detailIdsToSync);
        if (count($detailsToRemove) > 0) {
            CategorizationRule::where('user_id', $userId)
                ->where('category_id', $category->id)
                ->whereIn('detail_id', $detailsToRemove)
                ->delete();
        }

        return response()->json(['status' => 'ok', 'message' => 'Reglas actualizadas']);
    }
}
