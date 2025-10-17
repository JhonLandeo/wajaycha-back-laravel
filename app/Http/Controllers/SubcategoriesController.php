<?php

namespace App\Http\Controllers;

use App\Models\SubCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;

class SubcategoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $per_page = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $subcategories = SubCategory::paginate($per_page, ['*'], 'page', $page);

        return response()->json($subcategories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $subcategories = SubCategory::create($request->all());
        return response()->json($subcategories, 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SubCategory $subcategories): JsonResponse
    {
        $subcategories->update($request->all());
        return response()->json($subcategories);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SubCategory $subcategories): JsonResponse
    {
        $subcategories->delete();
        return response()->json($subcategories, 200);
    }
}
