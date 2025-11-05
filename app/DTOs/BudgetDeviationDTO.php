<?php

namespace App\DTOs;

class BudgetDeviationDTO
{
    public function __construct(
        public readonly string $category,
        public readonly float $budgeted,
        public readonly float|int $real,
        public readonly float $variance,
        public readonly string $status,
    ) {}
}