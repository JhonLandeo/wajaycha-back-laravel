<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PaydayPlan;
use Illuminate\Support\LazyCollection;

/**
 * Owns `payday_plans`. `$userId` is an authorization boundary: the caller
 * supplies the authenticated user, never a value read from the request body.
 */
interface PaydayPlanRepositoryContract
{
    public function findForUser(int $userId): ?PaydayPlan;

    /**
     * Writes the user's one plan, replacing every declared value. Arbitrated by
     * `unq_payday_plans_user_id` (an upsert), so two concurrent saves cannot
     * produce two rows.
     *
     * @param  array{payday_day: int, declared_salary: string|int|float, savings_percent: int, is_active: bool}  $values
     */
    public function replaceForUser(int $userId, array $values): PaydayPlan;

    /**
     * Every active plan, lazily and in a stable order, for the daily run.
     *
     * @return LazyCollection<int, PaydayPlan>
     */
    public function activePlans(): LazyCollection;
}
