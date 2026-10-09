<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Unit;
use App\Models\ConsumptionHabit;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsumptionHabit>
 */
class ConsumptionHabitFactory extends Factory
{
    protected $model = ConsumptionHabit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'weekly_quantity' => fake()->randomFloat(3, 0.1, 10),
            'unit' => fake()->randomElement(Unit::values()),
            'is_active' => true,
        ];
    }
}
