<?php

namespace App\Http\Controllers;

use App\Http\Requests\Detail\StoreDetailRequest;
use App\Http\Requests\Detail\UpdateDetailRequest;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\TransactionYape;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;

class DetailsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $userId = Auth::id();
        $statement = 'SELECT * FROM get_details(?, ?, ?)';
        $params = [$perPage, $page, $userId];
        $data = DB::select($statement, $params);
        if (count($data) > 0) {
            $total = $data[0]->total_count;
        }
        $paginate = new LengthAwarePaginator($data, $total ?? 0, $perPage, $page);

        return response()->json($paginate);
    }

    public function store(StoreDetailRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = Auth::id();
        $detail = Detail::create($data);
        GenerateEmbeddingForDetail::dispatch($detail, $request->last_used_category_id);
        return response()->json($detail, 201);
    }

    public function update(UpdateDetailRequest $request, Detail $detail): JsonResponse
    {
        $data = $detail->update($request->validated());
        return response()->json($data);
    }
}
