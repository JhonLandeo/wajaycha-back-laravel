<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceRunStatus;
use App\Models\PriceIngestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceIngestionRun>
 */
class PriceIngestionRunFactory extends Factory
{
    protected $model = PriceIngestionRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'plazavea',
            'unit_key' => 'arroz',
            'status' => PriceRunStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'rows_written' => 0,
            'rows_rejected' => 0,
            'error' => null,
            'details' => null,
        ];
    }
}
