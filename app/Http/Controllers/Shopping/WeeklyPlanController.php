<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\BuildWeeklyPlanAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * spec.md "Weekly list derivation". Thin: reads nothing itself, decides
 * nothing itself — `BuildWeeklyPlanAction` wires the read, `ShoppingPlanService`
 * decides (design.md D1).
 *
 * Response contract for prices (grocery-prices): each line carries `price`
 * (`null` only when no price context could be built, otherwise `fresh`, `stale`
 * or `unknown` with source, attribution and period) and `wholesale_trend`; the
 * plan carries `estimate`. `price.estimated_cost` is PROPORTIONAL to
 * `to_buy_quantity` (round half up to cents) — it is not rounded up to whole
 * packs — and `estimate.total` sums only the priced lines, so a partial estimate
 * is a lower bound.
 */
final class WeeklyPlanController extends Controller
{
    public function __construct(
        private readonly BuildWeeklyPlanAction $buildAction,
    ) {}

    public function show(): JsonResponse
    {
        // NOTE FOR REVIEWERS: this GET may WRITE a grocery_budget_links pin
        // as a side effect (design.md D6, same as
        // GroceryBudgetLinkController@show) — it is not an accident, see
        // BuildWeeklyPlanAction::execute().
        $data = $this->buildAction->execute((int) Auth::id());

        return response()->json(['data' => $data]);
    }
}
