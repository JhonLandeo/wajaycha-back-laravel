<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaydayPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaydayPlan>
 */
class PaydayPlanFactory extends Factory
{
    protected $model = PaydayPlan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'payday_day' => 3,
            'declared_salary' => '3000.00',
            'savings_percent' => 10,
            'is_active' => true,
        ];
    }
}
