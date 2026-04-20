<?php

declare(strict_types=1);

namespace App\DTOs\Categories;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;

final class CategoryDataDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly float $monthly_budget,
        public readonly int $user_id,
        public readonly ?int $parent_id = null,
        public readonly ?int $pareto_classification_id = null,
    ) {
    }

    public static function fromStoreRequest(StoreCategoryRequest $request, int $userId): self
    {
        return new self(
            name: (string) $request->validated('name'),
            type: (string) $request->validated('type'),
            monthly_budget: (float) $request->validated('monthly_budget'),
            user_id: $userId,
            parent_id: $request->validated('parent_id') ? (int) $request->validated('parent_id') : null,
            pareto_classification_id: $request->validated('pareto_classification_id') ? (int) $request->validated('pareto_classification_id') : null,
        );
    }

    public static function fromUpdateRequest(UpdateCategoryRequest $request, int $userId): self
    {
        return new self(
            name: (string) ($request->validated('name') ?? ''),
            type: (string) ($request->validated('type') ?? ''),
            monthly_budget: (float) ($request->validated('monthly_budget') ?? 0),
            user_id: $userId,
            parent_id: $request->validated('parent_id') ? (int) $request->validated('parent_id') : null,
            pareto_classification_id: $request->validated('pareto_classification_id') ? (int) $request->validated('pareto_classification_id') : null,
        );
    }
}
