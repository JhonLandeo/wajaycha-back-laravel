<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\SpendShift;

/**
 * Puts two windows of per-category spending side by side and reports what moved.
 *
 * The hard part of this question is not the subtraction, it is which two windows
 * get subtracted — and that decision lives in {@see \App\DTOs\Coaching\MonthCursor}
 * rather than here, because it is calendar arithmetic and because getting it wrong
 * is silent. Comparing a month-to-date against a **whole** previous month answers
 * "everything went down" every day until the 30th, with real figures and a
 * completely false conclusion. This class assumes its two inputs already cover
 * comparable spans and does nothing to check it, which is exactly why the cursor
 * owns that guarantee in one place.
 *
 * Pure, like every other decider in this namespace, so the arithmetic can be
 * asserted against fixed rows instead of a seeded database.
 */
final class MonthOverMonthComparator
{
    /**
     * @param  array<int, object{category_id: int|null, category_name: string|null, total: float}>  $current
     * @param  array<int, object{category_id: int|null, category_name: string|null, total: float}>  $previous
     * @param  float  $minAmount  movements smaller than this in absolute soles are
     *                            dropped. Every category drifts a little every month;
     *                            a list that reports all of them buries the two lines
     *                            that are actually findings.
     * @param  int  $maxCategories  how many survive the ranking.
     * @return SpendShift[] ordered by absolute movement, largest first
     */
    public function compare(array $current, array $previous, float $minAmount, int $maxCategories): array
    {
        $currentByKey = $this->totalsByKey($current);
        $previousByKey = $this->totalsByKey($previous);
        $names = $this->namesByKey($current) + $this->namesByKey($previous);

        $shifts = [];

        // La union de las dos ventanas, no solo la actual. Una categoria que
        // desaparecio no tiene fila este mes y es justamente uno de los dos
        // hallazgos que valen: dejar de gastar en algo es un cambio.
        foreach (array_keys($currentByKey + $previousByKey) as $key) {
            $currentTotal = $currentByKey[$key] ?? 0.0;
            $previousTotal = $previousByKey[$key] ?? 0.0;
            $delta = $currentTotal - $previousTotal;

            if (abs($delta) < $minAmount) {
                continue;
            }

            $shifts[] = new SpendShift(
                categoryId: $key === '' ? null : (int) $key,
                name: $names[$key] ?? 'Sin categoría',
                current: $currentTotal,
                previous: $previousTotal,
                delta: $delta,
            );
        }

        // Por MAGNITUD, sin mirar el signo: el movimiento mas grande es el que hay
        // que contar primero, se haya ido para arriba o para abajo. Ordenar por
        // delta a secas pondria todas las bajadas al final aunque una de ellas sea
        // el cambio mas grande del mes.
        usort($shifts, fn (SpendShift $a, SpendShift $b): int => abs($b->delta) <=> abs($a->delta));

        return array_slice($shifts, 0, max($maxCategories, 0));
    }

    /**
     * Totals keyed by category id, with the uncategorised bucket under `''`.
     *
     * A string key throughout because null cannot be an array key and `0` would
     * collide with a real id — PHP would silently merge uncategorised spending
     * into whichever category happens to be id 0 the day one exists.
     *
     * @param  array<int, object{category_id: int|null, category_name: string|null, total: float}>  $rows
     * @return array<string, float>
     */
    private function totalsByKey(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $totals[$this->keyFor($row)] = (float) $row->total;
        }

        return $totals;
    }

    /**
     * @param  array<int, object{category_id: int|null, category_name: string|null, total: float}>  $rows
     * @return array<string, string>
     */
    private function namesByKey(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            if ($row->category_name !== null && $row->category_name !== '') {
                $names[$this->keyFor($row)] = (string) $row->category_name;
            }
        }

        return $names;
    }

    private function keyFor(object $row): string
    {
        return $row->category_id === null ? '' : (string) $row->category_id;
    }
}
