<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FinancialEntity>
 */
class FinancialEntityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'type' => $this->faker->randomElement(['Banco', 'Cooperativa', 'Caja Municipal', 'Caja Rural', 'Edpyme', 'Financiera', 'Microfinanciera', 'Mutuo', 'Otro']),
            'code' => $this->faker->unique()->word,
            'address' => $this->faker->address,
            'website' => $this->faker->url,
        ];
    }
}
