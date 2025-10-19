<?php

namespace App\Http\Controllers;

use App\Models\ParetoClassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParetoClassificationController extends Controller
{
   /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $category = ParetoClassification::paginate($perPage, ['*'], 'page', $page);

        return response()->json($category);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $data = ParetoClassification::create($request->all());
        return response()->json($data);
    }

    /**
     * Display the specified resource.
     */
    public function show(ParetoClassification $pareto_classification): JsonResponse
    {
        $data = $pareto_classification;
        return response()->json($data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ParetoClassification $pareto_classification): JsonResponse
    {
        $data = $pareto_classification;
        return response()->json($data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ParetoClassification $pareto_classification): JsonResponse
    {
        $data = $pareto_classification->update($request->all());
        return response()->json($data);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ParetoClassification $pareto_classification): JsonResponse
    {
        $data = $pareto_classification->delete();
        return response()->json($data);
    }
}
