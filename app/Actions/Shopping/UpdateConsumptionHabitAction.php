<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Models\ConsumptionHabit;
use App\Repositories\Contracts\ShoppingRepositoryContract;

final class UpdateConsumptionHabitAction
{
    public function __construct(
        private readonly ShoppingRepositoryContract $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated by UpdateConsumptionHabitRequest.
     */
    public function execute(ConsumptionHabit $consumptionHabit, array $data): ConsumptionHabit
    {
        $this->repository->updateConsumptionHabit($consumptionHabit, [
            'product_id' => (int) $data['product_id'],
            'weekly_quantity' => (float) $data['weekly_quantity'],
            'unit' => (string) $data['unit'],
        ]);

        return $consumptionHabit->fresh();
    }
}
