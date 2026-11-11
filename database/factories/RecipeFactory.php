<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'slug' => null,
            'name' => fake()->unique()->words(2, true),
            'base_servings' => 2,
            'meal_types' => ['lunch'],
            'prep_minutes' => fake()->numberBetween(15, 45),
            'instructions' => null,
            'is_active' => true,
        ];
    }

    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'slug' => Str::slug(fake()->unique()->words(3, true)),
        ]);
    }
}
