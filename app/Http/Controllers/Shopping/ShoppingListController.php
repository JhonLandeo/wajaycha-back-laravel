<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\BuildUnifiedShoppingListAction;
use App\DTOs\Shopping\PlanningWeek;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\SetShoppingListCheckRequest;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One Compra for the client (ADR-0048): menu + habits − pantry, with the
 * persisted check-off attached. Thin: the action wires the reads and the
 * service decides; this controller only validates the source filter and
 * translates the check endpoint.
 */
final class ShoppingListController extends Controller
{
    public function __construct(
        private readonly BuildUnifiedShoppingListAction $buildAction,
        private readonly ShoppingRepositoryContract $repository,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_start' => ['sometimes', 'date_format:Y-m-d'],
            'sources' => ['sometimes', 'string', 'regex:/^(menu|habits)(,(menu|habits))?$/'],
        ]);

        $sources = isset($validated['sources'])
            ? array_values(array_unique(explode(',', (string) $validated['sources'])))
            : ['menu', 'habits'];

        $asOf = CarbonImmutable::now();
        $start = isset($validated['week_start'])
            ? CarbonImmutable::parse((string) $validated['week_start'], 'America/Lima')
            : $asOf;

        $weekStart = PlanningWeek::forWeekStarting($start, $asOf)->startsOn->toDateString();

        return response()->json([
            'data' => $this->buildAction->execute((int) Auth::id(), $weekStart, $sources),
        ]);
    }

    public function setCheck(SetShoppingListCheckRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->repository->setShoppingListCheck(
            (int) Auth::id(),
            (string) $data['week_start'],
            (int) $data['product_id'],
            (bool) $data['checked'],
        );

        return response()->json([
            'data' => [
                'product_id' => (int) $data['product_id'],
                'checked' => (bool) $data['checked'],
            ],
        ]);
    }
}
