<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AcquisitionSource;
use App\Enums\Unit;
use App\Models\PantryItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PantryItem>
 */
class PantryItemFactory extends Factory
{
    protected $model = PantryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(3, 0.1, 10),
            'unit' => fake()->randomElement(Unit::values()),
            'acquisition_source' => fake()->randomElement(AcquisitionSource::values()),
            'acquired_on' => now()->toDateString(),
            'expires_on' => null,
            'note' => null,
        ];
    }
}
