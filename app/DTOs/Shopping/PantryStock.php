<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use App\Enums\Unit;
use Carbon\CarbonImmutable;

/**
 * One pantry row, detached from `App\Models\PantryItem` (design.md
 * "Inputs"). `$unit` is nullable on purpose: it holds `Unit::tryFrom()`'s
 * result, so an unrecognised column value arrives as `null` rather than
 * being coerced (D4) — the service reads a `null` unit as a mismatch, never
 * a guess. `acquisition_source` is deliberately absent: `ShoppingPlanService`
 * never reads it (design.md rule 6), so it has no reason to travel here.
 */
final class PantryStock
{
    public function __construct(
        public readonly int $productId,
        public readonly ?Unit $unit,
        public readonly float $quantity,
        public readonly ?CarbonImmutable $expiresOn,
    ) {}
}
