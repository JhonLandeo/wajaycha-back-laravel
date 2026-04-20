<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\ParetoClassification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'                      => fake()->words(2, true),
            'type'                      => fake()->randomElement(['expense', 'income']),
            'monthly_budget'            => fake()->randomFloat(2, 0, 500),
            'user_id'                   => User::factory(),
            'pareto_classification_id'  => ParetoClassification::factory(),
            'parent_id'                 => null,
        ];
    }

    /**
     * Estado para categoría hija (con padre asignado).
     */
    public function asChild(int $parentId): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parentId,
        ]);
    }
}
