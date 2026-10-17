<?php

declare(strict_types=1);

namespace App\Http\Controllers\PaydayPlan;

use App\Actions\PaydayPlan\UpdatePaydayPlanAction;
use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaydayPlan\UpdatePaydayPlanRequest;
use App\Models\PaydayPlan;
use App\Repositories\Contracts\PaydayPlanRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Reads and replaces the authenticated user's one payday plan. The user comes
 * from `Auth::id()` only; nothing in the body can name another one.
 */
final class PaydayPlanController extends Controller
{
    public function __construct(
        private readonly PaydayPlanRepositoryContract $plans,
        private readonly UpdatePaydayPlanAction $update,
    ) {}

    /** `{data: null}` when the user has not declared a plan: no invented defaults. */
    public function show(): JsonResponse
    {
        $plan = $this->plans->findForUser((int) Auth::id());

        return response()->json(['data' => $plan === null ? null : $this->present($plan)]);
    }

    public function update(UpdatePaydayPlanRequest $request): JsonResponse
    {
        $plan = $this->update->execute((int) Auth::id(), $request->validated());

        return response()->json(['data' => $this->present($plan)]);
    }

    /**
     * @return array{payday_day: int, declared_salary: string, savings_percent: int, is_active: bool, savings_amount: string}
     */
    private function present(PaydayPlan $plan): array
    {
        $snapshot = PaydayPlanSnapshot::fromDeclaration($plan->payday_day, (string) $plan->declared_salary, $plan->savings_percent);

        return [
            'payday_day' => $plan->payday_day,
            'declared_salary' => (string) $plan->declared_salary,
            'savings_percent' => $plan->savings_percent,
            'is_active' => $plan->is_active,
            'savings_amount' => $snapshot->savingsAmount,
        ];
    }
}
