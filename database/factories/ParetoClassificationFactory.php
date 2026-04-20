<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ParetoClassification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Pest\Support\Str;

/**
 * @extends Factory<ParetoClassification>
 */
class ParetoClassificationFactory extends Factory
{
    protected $model = ParetoClassification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $classes = ['Variables', 'Fijos', 'Ahorro'];

        return [
            // Quitamos el ->unique()
            'name' => $this->faker->randomElement($classes),
            'percentage' => fake()->randomFloat(2, 10, 80),
            'user_id'    => User::factory(),
        ];
    }
}
