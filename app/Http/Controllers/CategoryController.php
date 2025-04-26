<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $category = Category::paginate($perPage, ['*'], 'page', $page);

        return response()->json($category);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $data = Category::create($request->all());
        return response()->json($data);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category): JsonResponse
    {
        $data = $category;
        return response()->json($data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category): JsonResponse
    {
        $data = $category;
        return response()->json($data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $category->update($request->all());
        return response()->json($data);
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
