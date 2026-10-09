<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\ResolveGroceryCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\UpdateGroceryBudgetLinkRequest;
use App\Models\GroceryBudgetLink;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * spec.md "Ceiling is absent, exceeded, or present — never zero",
 * "Lazy-resolve-then-pin lifecycle". `show()` is the GET that writes
 * (design.md D6) — it may create the auto pin through
 * `ResolveGroceryCategoryAction` as a side effect of a read.
 */
final class GroceryBudgetLinkController extends Controller
{
    public function __construct(
        private readonly ResolveGroceryCategoryAction $resolveAction,
        private readonly GroceryBudgetRepositoryContract $repository,
    ) {}

    public function show(): JsonResponse
    {
        $userId = (int) Auth::id();

        // Deliberate side effect of a GET (design.md D6): this may INSERT a
        // grocery_budget_links row when no pin exists yet and the
        // configured category name resolves.
        $link = $this->resolveAction->execute($userId);

        return response()->json(['data' => $this->ceilingResponse($userId, $link)]);
    }

    public function update(UpdateGroceryBudgetLinkRequest $request): JsonResponse
    {
        $userId = (int) Auth::id();

        // Manual override (design.md D6): a genuine upsert on user_id, never
        // re-resolved by name afterward — findLink() always wins step 1.
        $link = $this->repository->pin($userId, (int) $request->validated('category_id'), 'manual');

        return response()->json(['data' => $this->ceilingResponse($userId, $link)]);
    }

    /**
     * Translates the pin plus the composed ceiling read into the three
     * states design.md D5 names, never a bare zero (spec.md "Ceiling is
     * absent, exceeded, or present — never zero"). No API Resources layer
     * (design.md D7).
     *
     * @return array<string, mixed>
     */
    private function ceilingResponse(int $userId, ?GroceryBudgetLink $link): array
    {
        if ($link === null) {
            return [
                'ceiling_state' => 'unlinked',
                'resolution' => 'name_not_found',
                'resolved_by' => null,
                'category_id' => null,
                'category_name' => null,
                'amount' => null,
                'spent' => null,
                'budget_period' => null,
            ];
        }

        $ceiling = $this->repository->ceilingFor($userId, CarbonImmutable::now());

        if ($ceiling === null) {
            // Defensive: the link resolved but the Category no longer does
            // (should be unreachable given ON DELETE CASCADE).
            return [
                'ceiling_state' => 'unlinked',
                'resolution' => null,
                'resolved_by' => $link->resolved_by,
                'category_id' => null,
                'category_name' => null,
                'amount' => null,
                'spent' => null,
                'budget_period' => null,
            ];
        }

        $state = $ceiling->monthlyBudget > 0 ? 'set' : 'unbudgeted';

        return [
            'ceiling_state' => $state,
            'resolution' => null,
            'resolved_by' => $link->resolved_by,
            'category_id' => $ceiling->categoryId,
            'category_name' => $ceiling->categoryName,
            'amount' => $state === 'set' ? $ceiling->monthlyBudget : null,
            'spent' => $state === 'set' ? $ceiling->spent : null,
            'budget_period' => $ceiling->budgetPeriod->value,
        ];
    }
}
