<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MealPlanEntry;
use App\Models\PantryConsumption;
use App\Models\PantryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PantryConsumption>
 */
class PantryConsumptionFactory extends Factory
{
    protected $model = PantryConsumption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pantry_item_id' => PantryItem::factory(),
            'meal_plan_entry_id' => MealPlanEntry::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'unit' => 'kg',
            'consumed_on' => now()->toDateString(),
            'reason' => 'cooked',
        ];
    }
}
