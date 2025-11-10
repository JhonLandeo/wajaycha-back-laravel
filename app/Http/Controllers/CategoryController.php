<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $per_page = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $userId = Auth::id();
        $categories = Category::query()
            ->where('user_id', $userId)
            ->whereNotNull('parent_id')
            ->with('paretoClassification')
            ->withCount('categorizationRules')
            ->orderBy('categorization_rules_count', 'desc')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'pareto_classification_id' => 'required|exists:pareto_classifications,id',
            'monthly_budget' => 'required|numeric|min:0',
        ]);
        $validatedData['user_id'] = $userId;
        $categories = Category::create($validatedData);
        return response()->json($categories, 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'pareto_classification_id' => 'required|exists:pareto_classifications,id',
            'monthly_budget' => 'required|numeric|min:0',
        ]);
        $category->update($validatedData);
        return response()->json($category);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category): JsonResponse
    {
        $data = $category->delete();
        return response()->json($data);
    }
}
