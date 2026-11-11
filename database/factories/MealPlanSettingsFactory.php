<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MealPlanSettings;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanSettings>
 */
class MealPlanSettingsFactory extends Factory
{
    protected $model = MealPlanSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_size' => 2,
            'meal_types' => ['breakfast', 'lunch', 'dinner'],
            'plan_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'excluded_terms' => [],
            'max_prep_minutes' => null,
        ];
    }
}
