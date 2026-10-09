<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StoreProductRequest;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class ProductCatalogueController extends Controller
{
    public function __construct(
        private readonly ShoppingRepositoryContract $repository,
    ) {}

    public function index(): JsonResponse
    {
        $products = $this->repository->listProductsFor((int) Auth::id());

        return response()->json(['data' => $products]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->repository->createProduct(
            (int) Auth::id(),
            (string) $request->input('name'),
            (string) $request->input('unit'),
        );

        return response()->json(['data' => $product], 201);
    }
}
