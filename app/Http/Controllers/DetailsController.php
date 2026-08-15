<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Detail\StoreDetailRequest;
use App\Http\Requests\Detail\UpdateDetailRequest;
use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Detail;
use App\Repositories\Contracts\DetailRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class DetailsController extends Controller
{
    public function __construct(
        private readonly DetailRepositoryContract $details,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);

        $rows = $this->details->listForUser((int) Auth::id(), $perPage, $page);

        // Every row repeats the same total; an empty page reports zero.
        $total = $rows === [] ? 0 : (int) $rows[0]->total_count;

        return response()->json(
            new LengthAwarePaginator($rows, $total, $perPage, $page)
        );
    }

    public function store(StoreDetailRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = Auth::id();

        $detail = Detail::create($data);
        GenerateEmbeddingForDetail::dispatch($detail, $request->last_used_category_id);

        return response()->json($detail, 201);
    }

    public function update(UpdateDetailRequest $request, int $id): JsonResponse
    {
        // Resolved explicitly rather than by route-model binding, which matches on the
        // primary key alone and would hand over another user's Detail.
        $detail = Detail::query()
            ->whereKey($id)
            ->where('user_id', (int) Auth::id())
            ->first();

        if (! $detail) {
            return response()->json(['message' => 'Detalle no encontrado'], 404);
        }

        $data = $detail->update($request->validated());

        return response()->json($data);
    }
}
