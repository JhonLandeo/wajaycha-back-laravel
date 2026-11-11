<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FoodBudget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodBudget>
 */
class FoodBudgetFactory extends Factory
{
    protected $model = FoodBudget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'monthly_amount' => 400,
            'shopping_day' => 7,
        ];
    }
}
