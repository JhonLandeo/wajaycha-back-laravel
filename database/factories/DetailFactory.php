<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Detail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Detail>
 */
class DetailFactory extends Factory
{
    protected $model = Detail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'description'    => fake()->words(3, true),
            'operation_type' => fake()->randomElement(['POS_GENERICO', 'YAPE', 'PLIN', 'TRANSFERENCIA']),
            'entity_clean'   => fake()->company(),
            'embedding'      => null,
            'last_used_category_id' => null,
        ];
    }
}
