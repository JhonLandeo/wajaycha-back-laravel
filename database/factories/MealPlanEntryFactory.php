<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MealEntryKind;
use App\Enums\MealType;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanEntry>
 */
class MealPlanEntryFactory extends Factory
{
    protected $model = MealPlanEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_date' => now()->startOfWeek()->toDateString(),
            'meal_type' => MealType::Lunch,
            'kind' => MealEntryKind::Recipe,
            'recipe_id' => Recipe::factory(),
            'servings' => 2,
            'cook_servings' => 2,
            'leftover_of_entry_id' => null,
            'notes' => null,
        ];
    }
}
