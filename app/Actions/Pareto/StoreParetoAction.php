<?php

declare(strict_types=1);

namespace App\Actions\Pareto;

use App\DTOs\Pareto\ParetoClassificationDTO;
use App\Models\ParetoClassification;
use App\Repositories\Contracts\ParetoRepositoryContract;

final class StoreParetoAction
{
    public function __construct(
        private readonly ParetoRepositoryContract $repository
    ) {
    }

    public function execute(ParetoClassificationDTO $dto): ParetoClassification
    {
        return $this->repository->create([
            'name' => $dto->name,
            'percentage' => $dto->percentage,
            'user_id' => $dto->user_id,
        ]);
    }
}
