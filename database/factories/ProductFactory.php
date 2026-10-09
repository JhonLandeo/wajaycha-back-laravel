<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Unit;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->unique()->words(2, true),
            'unit' => fake()->randomElement(Unit::values()),
            'is_active' => true,
        ];
    }

    /**
     * A user's own private addition: no slug, owned by that user.
     */
    public function ownedBy(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'slug' => null,
        ]);
    }
}
