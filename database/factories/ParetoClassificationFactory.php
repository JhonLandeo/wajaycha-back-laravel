<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ParetoClassification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParetoClassification>
 */
class ParetoClassificationFactory extends Factory
{
    private static int $sequence = 0;

    protected $model = ParetoClassification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // UserObserver seeds every new user with 'Fijos', 'Variables' and
        // 'Ahorro', and unq_pareto_classifications_user_id_name forbids
        // repeating a name per user. Generated names must therefore never
        // reuse the defaults; a counter guarantees that.
        $index = ++self::$sequence;

        return [
            'name' => "Clasificación {$index}",
            'percentage' => fake()->randomFloat(2, 10, 80),
            'user_id' => User::factory(),
        ];
    }
}
