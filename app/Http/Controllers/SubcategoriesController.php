<?php

namespace App\Http\Controllers;

use App\Models\SubCategory;
use Illuminate\Http\Request;

class SubcategoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $per_page = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $subcategories = SubCategory::paginate($per_page, ['*'], 'page', $page);

        return response()->json($subcategories);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $subcategories = SubCategory::create($request->all());
        return response()->json($subcategories, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(SubCategory $subcategories)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SubCategory $subcategories)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SubCategory $subcategories)
    {
        $subcategories->update($request->all());
        return response()->json($subcategories);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SubCategory $subcategories)
    {
        $subcategories->delete();
        return response()->json($subcategories, 204);
    }
}
