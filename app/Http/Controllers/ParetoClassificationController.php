<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParetoClassification\StoreParetoClassificationRequest;
use App\Http\Requests\ParetoClassification\UpdateParetoClassificationRequest;
use App\Models\ParetoClassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParetoClassificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $month = $request->input('month');
        $year = $request->input('year');
        $userId = Auth::id();

        $result = DB::select('SELECT * FROM get_pareto_monthly_report(?, ?, ?, ?, ?)', [
            $userId,
            $month,
            $year,
            $page,
            $perPage
        ]);

        $total = count($result) > 0 ? $result[0]->total_records : 0;

        foreach ($result as $row) {
            $row->category_names = json_decode($row->category_names);
        }

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $result,
            $total,
            $perPage,
            $page
        );

        return response()->json($paginator);
    }

    /**
     * Display a listing of all resources.
     */
    public function all(): JsonResponse
    {
        $userId = Auth::id();
        $data = ParetoClassification::where('user_id', $userId)->get();
        return response()->json($data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreParetoClassificationRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $validatedData = $request->validated();
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
    public function update(UpdateParetoClassificationRequest $request, ParetoClassification $pareto_classification): JsonResponse
    {
        $validatedData = $request->validated();
        $data = $pareto_classification->update($validatedData);
        return response()->json($data);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ParetoClassification $pareto_classification): JsonResponse
    {
        if ($pareto_classification->categories()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar porque ya se está haciendo uso de estos.',
            ], 422);
        }

        $data = $pareto_classification->delete();
        return response()->json($data);
    }

    public function categories(ParetoClassification $pareto_classification): JsonResponse
    {
        $categories = $pareto_classification->categories()
            ->withCount('categorizationRules')
            ->orderBy('categorization_rules_count', 'desc')
            ->get();

        return response()->json($categories);
    }
}
