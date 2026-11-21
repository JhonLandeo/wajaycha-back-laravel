<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ShoppingListCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListCheck>
 */
class ShoppingListCheckFactory extends Factory
{
    protected $model = ShoppingListCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => now()->startOfWeek()->toDateString(),
            'product_id' => Product::factory(),
        ];
    }
}
