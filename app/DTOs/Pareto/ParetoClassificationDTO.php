<?php

declare(strict_types=1);

namespace App\DTOs\Pareto;

use App\Http\Requests\ParetoClassification\StoreParetoClassificationRequest;
use App\Http\Requests\ParetoClassification\UpdateParetoClassificationRequest;

final class ParetoClassificationDTO
{
    public function __construct(
        public readonly string $name,
        public readonly float $percentage,
        public readonly int $user_id,
        public readonly ?int $id = null,
    ) {
    }

    public static function fromStoreRequest(StoreParetoClassificationRequest $request, int $userId): self
    {
        return new self(
            name: (string) $request->validated('name'),
            percentage: (float) $request->validated('percentage'),
            user_id: $userId
        );
    }

    public static function fromUpdateRequest(UpdateParetoClassificationRequest $request, int $userId, int $id): self
    {
        return new self(
            name: (string) ($request->validated('name') ?? ''),
            percentage: (float) ($request->validated('percentage') ?? 0),
            user_id: $userId,
            id: $id
        );
    }
}
