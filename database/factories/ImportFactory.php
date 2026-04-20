<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    protected $model = Import::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'             => User::factory(),
            'name'                => fake()->uuid() . '.pdf',
            'extension'           => 'pdf',
            'path'                => 'files/bcp/' . fake()->uuid() . '.pdf',
            'mime'                => 'application/pdf',
            'size'                => fake()->numberBetween(10000, 500000),
            'status'              => 'completed',
            'financial_entity_id' => null,
            'payment_service_id'  => null,
        ];
    }

    /**
     * Estado para imports en proceso.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Estado para imports fallidos.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }
}
