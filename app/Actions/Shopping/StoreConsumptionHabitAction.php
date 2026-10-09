<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Models\ConsumptionHabit;
use App\Repositories\Contracts\ShoppingRepositoryContract;

final class StoreConsumptionHabitAction
{
    public function __construct(
        private readonly ShoppingRepositoryContract $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated by StoreConsumptionHabitRequest.
     */
    public function execute(int $userId, array $data): ConsumptionHabit
    {
        return $this->repository->createConsumptionHabit([
            'user_id' => $userId,
            'product_id' => (int) $data['product_id'],
            'weekly_quantity' => (float) $data['weekly_quantity'],
            'unit' => (string) $data['unit'],
        ]);
    }
}
