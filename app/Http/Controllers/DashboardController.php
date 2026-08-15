<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Dashboard\DashboardScope;
use App\Enums\DashboardMeasure;
use App\Repositories\Contracts\DashboardRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Orchestrates the dashboard read model. Decides nothing.
 *
 * Its whole job is translation: turn an HTTP request into a scope and a measure, ask
 * the port, hand back JSON. The wire names — `isChecked`, `year`, `month` — stop here.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardRepositoryContract $dashboard,
    ) {}

    public function kpiData(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->kpi($this->scopeFrom($request)));
    }

    public function topFiveData(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->topFive($this->scopeFrom($request)));
    }

    public function getWeeklyData(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->weekly(
            $this->scopeFrom($request),
            $this->measureFrom($request),
        ));
    }

    public function getMonthlyData(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->monthly(
            $this->scopeFrom($request),
            $this->measureFrom($request),
        ));
    }

    public function getTransactionByCategory(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->spendByCategory(
            $this->scopeFrom($request),
            $request->input('search'),
        ));
    }

    /**
     * The owner comes from the token, never from the payload. A caller cannot ask for
     * someone else's dashboard by naming them.
     */
    private function scopeFrom(Request $request): DashboardScope
    {
        return new DashboardScope(
            userId: (int) Auth::id(),
            year: $request->input('year') !== null ? (int) $request->input('year') : null,
            month: $request->input('month') !== null ? (int) $request->input('month') : null,
        );
    }

    /**
     * `isChecked` is the checkbox the SPA sends. This is the only place that name is
     * allowed to mean something.
     */
    private function measureFrom(Request $request): DashboardMeasure
    {
        return $request->boolean('isChecked')
            ? DashboardMeasure::Count
            : DashboardMeasure::Amount;
    }
}
