<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

final class FoodBudgetCategory
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId,
        public readonly float $monthlyBudget,
    ) {}

    /**
     * @return array{id: int, name: string, parent_id: int|null, monthly_budget: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parentId,
            'monthly_budget' => $this->monthlyBudget,
        ];
    }
}
