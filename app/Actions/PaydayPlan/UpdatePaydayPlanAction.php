<?php

declare(strict_types=1);

namespace App\Actions\PaydayPlan;

use App\Models\PaydayPlan;
use App\Repositories\Contracts\PaydayPlanRepositoryContract;

final class UpdatePaydayPlanAction
{
    public function __construct(
        private readonly PaydayPlanRepositoryContract $plans,
    ) {}

    /**
     * Replaces the user's plan with exactly what they declared. Nothing is
     * proposed, defaulted or adjusted (PS-1).
     *
     * @param  array<string, mixed>  $data  Already validated by UpdatePaydayPlanRequest.
     */
    public function execute(int $userId, array $data): PaydayPlan
    {
        return $this->plans->replaceForUser($userId, [
            'payday_day' => (int) $data['payday_day'],
            'declared_salary' => number_format((float) $data['declared_salary'], 2, '.', ''),
            'savings_percent' => (int) $data['savings_percent'],
            'is_active' => (bool) $data['is_active'],
        ]);
    }
}
