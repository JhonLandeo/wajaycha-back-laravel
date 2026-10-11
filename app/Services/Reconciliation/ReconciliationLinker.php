<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Enums\SourceType;
use App\Models\Transaction;

/**
 * Decides which of two records of one movement keeps counting.
 *
 * Extracted so the automatic path and the user's path cannot drift: a pair the
 * system merges and the same pair a person confirms must produce the identical
 * link, or the totals would depend on how the decision was reached.
 */
class ReconciliationLinker
{
    /**
     * Which row survives in the totals.
     *
     * Decided by provenance, never by which arrived first. The capture usually
     * arrives first — it happens at the till — and the export that outranks it
     * arrives weeks later; ordering by insertion would keep the weaker record
     * every single time.
     *
     * @return array{0: Transaction, 1: Transaction} master, then satellite
     */
    public function rank(Transaction $a, Transaction $b): array
    {
        $authorityOfA = SourceType::fromColumn($a->source_type)->authority();
        $authorityOfB = SourceType::fromColumn($b->source_type)->authority();

        if ($authorityOfA === $authorityOfB) {
            // Empate real: dos fuentes igual de confiables. El id mas bajo gana solo
            // para que la eleccion sea reproducible, no porque signifique algo.
            return $a->id <= $b->id ? [$a, $b] : [$b, $a];
        }

        return $authorityOfA > $authorityOfB ? [$a, $b] : [$b, $a];
    }

    /**
     * Points the weaker record at the stronger one and returns the pair.
     *
     * @return array{0: Transaction, 1: Transaction} master first, satellite second —
     *                                               the order `rank()` decided, which
     *                                               every caller destructures by
     *                                               position
     */
    public function link(Transaction $a, Transaction $b): array
    {
        [$master, $satellite] = $this->rank($a, $b);

        $satellite->update(['matched_transaction_id' => $master->id]);

        // El maestro es la fila que se ve, y suele ser la que menos dice: el
        // extracto trae "YAPE JOSE TOR" y la categoria la puso alguien sobre el
        // Excel. Si no se pasa, el movimiento queda sin categoria en los reportes.
        // Solo se llena un vacio: una categoria que el maestro ya tiene es una
        // decision de alguien y no se pisa.
        if ($master->category_id === null && $satellite->category_id !== null) {
            $master->update(['category_id' => $satellite->category_id]);
        }

        return [$master, $satellite];
    }

    /**
     * Puts both rows back in the totals.
     *
     * The category the master inherited in `link()` stays: once visible it may
     * have been confirmed by someone, and the row cannot tell which.
     */
    public function unlink(Transaction $a, Transaction $b): void
    {
        [, $satellite] = $this->rank($a, $b);

        $satellite->update(['matched_transaction_id' => null]);
    }
}
