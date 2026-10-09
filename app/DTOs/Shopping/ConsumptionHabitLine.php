<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use App\Enums\Unit;

/**
 * A declared weekly need, detached from `App\Models\ConsumptionHabit`
 * (design.md "Inputs", `BoundariesTest.php`: a DTO may not import
 * `App\Models`). Only active habits ever become one — the repository read
 * that builds these already filters `is_active` (M3, `activeHabitsFor()`).
 */
final class ConsumptionHabitLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly Unit $unit,
        public readonly float $weeklyQuantity,
    ) {}
}
