<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use App\DTOs\Prices\LinePrice;
use App\DTOs\Prices\WholesaleTrend;
use App\Enums\Unit;
use Carbon\CarbonImmutable;

/**
 * One line the user still needs to buy (design.md "Output — every line is
 * diagnosable at its source"). Carries `$neededQuantity` and
 * `$availableQuantity` separately from `$toBuyQuantity` on purpose: a wrong
 * line must be traceable to the habit or the pantry row that caused it,
 * not just to a final number.
 *
 * `$price` and `$wholesaleTrend` are annotations (grocery-prices spec): both stay
 * null when the plan was computed without a price context, and neither can
 * change any quantity above.
 */
final class ShoppingListLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly Unit $unit,
        public readonly float $neededQuantity,
        public readonly float $availableQuantity,
        public readonly float $expiredQuantity,
        public readonly float $unmatchedUnitQuantity,
        public readonly float $toBuyQuantity,
        public readonly bool $hasUnitMismatch,
        public readonly ?CarbonImmutable $soonestExpiryOn,
        public readonly ?LinePrice $price = null,
        public readonly ?WholesaleTrend $wholesaleTrend = null,
    ) {}

    /**
     * The same line with its price annotations attached. Every quantity is
     * carried over untouched — annotating never resizes a line.
     */
    public function withPrice(?LinePrice $price, ?WholesaleTrend $wholesaleTrend): self
    {
        return new self(
            productId: $this->productId,
            productName: $this->productName,
            unit: $this->unit,
            neededQuantity: $this->neededQuantity,
            availableQuantity: $this->availableQuantity,
            expiredQuantity: $this->expiredQuantity,
            unmatchedUnitQuantity: $this->unmatchedUnitQuantity,
            toBuyQuantity: $this->toBuyQuantity,
            hasUnitMismatch: $this->hasUnitMismatch,
            soonestExpiryOn: $this->soonestExpiryOn,
            price: $price,
            wholesaleTrend: $wholesaleTrend,
        );
    }

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
            'expired_quantity' => $this->expiredQuantity,
            'unmatched_unit_quantity' => $this->unmatchedUnitQuantity,
            'to_buy_quantity' => $this->toBuyQuantity,
            'has_unit_mismatch' => $this->hasUnitMismatch,
            'soonest_expiry_on' => $this->soonestExpiryOn?->toDateString(),
            'price' => $this->price?->toArray(),
            'wholesale_trend' => $this->wholesaleTrend?->toArray(),
        ];
    }
}
