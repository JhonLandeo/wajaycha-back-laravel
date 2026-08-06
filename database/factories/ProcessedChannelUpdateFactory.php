<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProcessedChannelUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessedChannelUpdate>
 */
class ProcessedChannelUpdateFactory extends Factory
{
    protected $model = ProcessedChannelUpdate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => 'telegram',
            'external_update_id' => (string) fake()->unique()->numberBetween(1, 999999999),
            'processed_at' => now(),
        ];
    }
}
