<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeIngredient>
 */
class RecipeIngredientFactory extends Factory
{
    protected $model = RecipeIngredient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'unit' => 'unidad',
            'is_optional' => false,
        ];
    }
}
