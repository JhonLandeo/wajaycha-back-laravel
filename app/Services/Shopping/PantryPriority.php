<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\PantryStock;
use Carbon\CarbonImmutable;

/**
 * Prioriza los lotes de despensa por urgencia (ADR-0035).
 *
 * `expires_on` manda cuando el usuario lo cargó. Si no, se estima con
 * `acquired_on + shelf_life_days`. Un producto sin fecha ni vida útil conocida
 * queda al final, pero sigue siendo despensa: si es el único producto, es el
 * primero que la sugerencia debe usar.
 */
final class PantryPriority
{
    /**
     * @param  array<int, PantryStock>  $pantry
     * @return array<int, int|null> product id => días restantes (0 = hoy), null = sin fecha
     */
    public function daysLeftByProduct(array $pantry, CarbonImmutable $asOf): array
    {
        $today = $asOf->setTimezone('America/Lima')->startOfDay();
        $byProduct = [];

        foreach ($pantry as $stock) {
            if ($stock->unit === null || $stock->quantity <= 0.0) {
                continue;
            }

            $days = $this->daysLeft($stock, $today);

            if ($days === null) {
                $byProduct[$stock->productId] ??= null;

                continue;
            }

            if ($days < 0) {
                continue;
            }

            $current = $byProduct[$stock->productId] ?? null;
            $byProduct[$stock->productId] = $current === null ? $days : min($current, $days);
        }

        return $byProduct;
    }

    /**
     * @param  array<int, PantryStock>  $pantry
     * @return array<int, int> product ids, el primero es el más urgente
     */
    public function orderedProductIds(array $pantry, CarbonImmutable $asOf): array
    {
        $days = $this->daysLeftByProduct($pantry, $asOf);
        $quantities = [];

        foreach ($pantry as $stock) {
            if ($stock->unit === null || $stock->quantity <= 0.0) {
                continue;
            }

            $quantities[$stock->productId] = ($quantities[$stock->productId] ?? 0.0) + $stock->quantity;
        }

        uksort($days, static function (int $a, int $b) use ($days, $quantities): int {
            $daysA = $days[$a] ?? null;
            $daysB = $days[$b] ?? null;

            if ($daysA === null && $daysB === null) {
                return ($quantities[$b] ?? 0.0) <=> ($quantities[$a] ?? 0.0) ?: $a <=> $b;
            }

            if ($daysA === null) {
                return 1;
            }

            if ($daysB === null) {
                return -1;
            }

            return $daysA <=> $daysB
                ?: (($quantities[$b] ?? 0.0) <=> ($quantities[$a] ?? 0.0))
                ?: $a <=> $b;
        });

        return array_keys($days);
    }

    public function daysLeft(PantryStock $stock, CarbonImmutable $today): ?int
    {
        $expires = $stock->expiresOn;

        if ($expires === null && $stock->acquiredOn !== null && $stock->shelfLifeDays !== null) {
            $expires = $stock->acquiredOn->addDays($stock->shelfLifeDays);
        }

        if ($expires === null) {
            return null;
        }

        $todayDay = CarbonImmutable::parse($today->toDateString(), 'America/Lima')->startOfDay();
        $expiresDay = CarbonImmutable::parse($expires->toDateString(), 'America/Lima')->startOfDay();

        return (int) $todayDay->diffInDays($expiresDay, false);
    }
}
