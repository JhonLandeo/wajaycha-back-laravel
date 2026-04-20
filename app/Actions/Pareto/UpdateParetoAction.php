<?php

declare(strict_types=1);

namespace App\Actions\Pareto;

use App\DTOs\Pareto\ParetoClassificationDTO;
use App\Models\ParetoClassification;
use App\Repositories\Contracts\ParetoRepositoryContract;

final class UpdateParetoAction
{
    public function __construct(
        private readonly ParetoRepositoryContract $repository
    ) {
    }

    public function execute(ParetoClassification $pareto, ParetoClassificationDTO $dto): bool
    {
        return $this->repository->update($pareto, [
            'name' => $dto->name,
            'percentage' => $dto->percentage,
        ]);
    }
}
