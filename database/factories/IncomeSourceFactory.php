<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IncomeSourceKind;
use App\Models\IncomeSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeSource>
 */
class IncomeSourceFactory extends Factory
{
    private static int $sequence = 0;

    protected $model = IncomeSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = ++self::$sequence;

        return [
            'user_id' => User::factory(),
            'name' => "Ingreso {$index}",
            'kind' => IncomeSourceKind::Salary,
        ];
    }
}
