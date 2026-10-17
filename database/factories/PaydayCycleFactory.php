<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaydayCycleStatus;
use App\Models\PaydayCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaydayCycle>
 */
class PaydayCycleFactory extends Factory
{
    protected $model = PaydayCycle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'period_month' => '2026-10-01',
            'payday_on' => '2026-10-03',
            'plan_snapshot' => [
                'payday_day' => 3,
                'declared_salary' => '3000.00',
                'savings_percent' => 10,
                'savings_amount' => '300.00',
            ],
            'status' => PaydayCycleStatus::Pending,
            'send_attempts' => 0,
        ];
    }
}
