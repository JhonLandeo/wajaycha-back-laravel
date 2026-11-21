<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\ConsumptionHabitLine;

/**
 * Merges need lines that come from different sources into one line per product.
 *
 * A product bought for the menu and declared as a habit is still one product:
 * the quantities add up and the pantry subtracts once, not once per source.
 * Both sources use the product's canonical unit, so a unit mismatch here would
 * be a data defect; the first line's unit wins rather than inventing a
 * conversion.
 */
final class ShoppingNeedsMerger
{
    /**
     * @param  array<int, ConsumptionHabitLine>  ...$lineSets
     * @return array<int, ConsumptionHabitLine>
     */
    public function merge(array ...$lineSets): array
    {
        $byProduct = [];

        foreach ($lineSets as $lines) {
            foreach ($lines as $line) {
                $byProduct[$line->productId] ??= [
                    'name' => $line->productName,
                    'unit' => $line->unit,
                    'quantity' => 0.0,
                ];
                $byProduct[$line->productId]['quantity'] += $line->weeklyQuantity;
            }
        }

        $merged = [];
        foreach ($byProduct as $productId => $need) {
            $merged[] = new ConsumptionHabitLine(
                productId: $productId,
                productName: $need['name'],
                unit: $need['unit'],
                weeklyQuantity: round($need['quantity'], 3),
            );
        }

        usort($merged, static fn (ConsumptionHabitLine $a, ConsumptionHabitLine $b): int => $a->productName <=> $b->productName);

        return $merged;
    }
}
