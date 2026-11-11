<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\UpdateFoodBudgetRequest;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class FoodBudgetController extends Controller
{
    public function __construct(
        private readonly FoodBudgetRepositoryContract $repository,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->responseFor((int) Auth::id())]);
    }

    public function update(UpdateFoodBudgetRequest $request): JsonResponse
    {
        $userId = (int) Auth::id();

        $this->repository->save(
            $userId,
            array_map('intval', $request->validated('category_ids')),
            (float) $request->validated('monthly_amount'),
            (int) $request->validated('shopping_day'),
        );

        return response()->json(['data' => $this->responseFor($userId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFor(int $userId): array
    {
        $snapshot = $this->repository->snapshotFor($userId, CarbonImmutable::now());

        if ($snapshot === null) {
            return [
                'configured' => false,
                'monthly_amount' => null,
                'spent' => 0.0,
                'available' => 0.0,
                'is_exceeded' => false,
                'overspend' => 0.0,
                'shopping_day' => 7,
                'shopping_days_remaining' => 0,
                'weekly_target' => null,
                'suggested_amount' => 0.0,
                'category_ids' => [],
                'categories' => [],
            ];
        }

        return [
            'configured' => true,
            ...$snapshot->toArray(),
        ];
    }
}
