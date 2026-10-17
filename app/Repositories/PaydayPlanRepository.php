<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PaydayPlan;
use App\Repositories\Contracts\PaydayPlanRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;

final class PaydayPlanRepository implements PaydayPlanRepositoryContract
{
    public function findForUser(int $userId): ?PaydayPlan
    {
        /** @var PaydayPlan|null $plan */
        $plan = PaydayPlan::query()->where('user_id', $userId)->first();

        return $plan;
    }

    public function replaceForUser(int $userId, array $values): PaydayPlan
    {
        $now = CarbonImmutable::now();

        // `INSERT ... ON CONFLICT (user_id) DO UPDATE`: the unique index is the
        // arbiter, not a read followed by a write.
        PaydayPlan::upsert(
            [[
                'user_id' => $userId,
                'payday_day' => $values['payday_day'],
                'declared_salary' => $values['declared_salary'],
                'savings_percent' => $values['savings_percent'],
                'is_active' => $values['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            uniqueBy: ['user_id'],
            update: ['payday_day', 'declared_salary', 'savings_percent', 'is_active', 'updated_at'],
        );

        $plan = $this->findForUser($userId);

        if ($plan === null) {
            throw new \RuntimeException('El plan de cobro no se pudo releer tras escribirlo.');
        }

        return $plan;
    }

    public function activePlans(): LazyCollection
    {
        return PaydayPlan::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->cursor();
    }
}
