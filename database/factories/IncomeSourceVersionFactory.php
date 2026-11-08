<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IncomeSource;
use App\Models\IncomeSourceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeSourceVersion>
 */
class IncomeSourceVersionFactory extends Factory
{
    protected $model = IncomeSourceVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'income_source_id' => IncomeSource::factory(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'effective_from' => now()->startOfMonth(),
            'effective_to' => null,
        ];
    }
}
