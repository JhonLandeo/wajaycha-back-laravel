<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use App\Enums\Unit;

/**
 * A habit fully covered by available pantry stock (design.md rule 7):
 * `toBuyQuantity` would be `0.0`, so the product drops off `lines` — but it
 * carries `$neededQuantity` and `$availableQuantity` so the user can see
 * WHY it dropped off, instead of the product silently disappearing.
 */
final class CoveredLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly Unit $unit,
        public readonly float $neededQuantity,
        public readonly float $availableQuantity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'unit' => $this->unit->value,
            'needed_quantity' => $this->neededQuantity,
            'available_quantity' => $this->availableQuantity,
        ];
    }
}
