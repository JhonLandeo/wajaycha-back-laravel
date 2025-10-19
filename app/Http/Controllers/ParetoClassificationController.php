<?php

namespace App\Http\Controllers;

use App\Models\ParetoClassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ParetoClassificationController extends Controller
{
   /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $category = ParetoClassification::where('user_id', Auth::id())
        ->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($category);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'percentage' => 'required|numeric|between:0,100',
        ]);
        $validatedData['user_id'] = $userId;
        $data = ParetoClassification::create($validatedData);
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
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'percentage' => 'required|numeric|between:0,100',
        ]);
        $data = $pareto_classification->update($validatedData);
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
