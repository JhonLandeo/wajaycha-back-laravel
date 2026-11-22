<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Enums\SourceType;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $this->inheritCategory($master, $satellite);

        return [$master, $satellite];
    }

    /**
     * Fills the master's empty category from a satellite that has one.
     *
     * Public so that a caller which decides the master by other means — the
     * cleanup of re-imports, which links to the root of an existing chain rather
     * than to whichever row `rank()` would pick — still applies this one rule
     * instead of a copy of it.
     */
    public function inheritCategory(Transaction $master, Transaction $satellite): void
    {
        // Un maestro repartido no admite una categoría única: ya tiene su
        // atribución completa. Asignarle una rompería el invariante.
        if ($master->category_id !== null || $this->hasSplits($master)) {
            return;
        }

        // El reparto del satélite es más detallado que una categoría suelta y se
        // adopta solo cuando el maestro no decidió nada. Si los montos no
        // coinciden, `adoptSplits()` lo rechaza y se cae a la herencia de
        // categoría de siempre.
        if ($this->adoptSplits($master, $satellite)) {
            return;
        }

        if ($satellite->category_id !== null) {
            $master->update(['category_id' => $satellite->category_id]);
        }
    }

    /**
     * Copies the satellite's split onto a master that has neither a category nor
     * a split of its own.
     *
     * The satellite keeps its rows: it stops counting through
     * `matched_transaction_id`, but if the link is undone it becomes visible
     * again with its own attribution intact. The copy exists because the master
     * is the row the reports read, and losing a user's split on a duplicate
     * would leave the visible row with no category at all.
     *
     * Only an exact amount match is adopted. Two records of one movement should
     * have the same total, but if they do not, copying would violate the sum
     * invariant; the safe answer is to leave the master as it was.
     */
    public function adoptSplits(Transaction $master, Transaction $satellite): bool
    {
        if ($master->category_id !== null || $this->hasSplits($master)) {
            return false;
        }

        $splits = TransactionSplit::query()
            ->where('transaction_id', $satellite->id)
            ->get();

        if ($splits->isEmpty()) {
            return false;
        }

        $splitCents = (int) $splits->sum(
            static fn (TransactionSplit $split): int => (int) round((float) $split->amount * 100)
        );
        $masterCents = (int) round((float) $master->amount * 100);

        if ($splitCents !== $masterCents) {
            Log::warning('No se adoptó el reparto al conciliar: los montos no coinciden.', [
                'master_id' => $master->id,
                'satellite_id' => $satellite->id,
                'master_amount' => $master->amount,
                'split_total' => $splitCents / 100,
            ]);

            return false;
        }

        DB::transaction(function () use ($master, $splits): void {
            $now = now();

            TransactionSplit::query()->insert($splits->map(
                static fn (TransactionSplit $split): array => [
                    'transaction_id' => $master->id,
                    'category_id' => $split->category_id,
                    'user_id' => $master->user_id,
                    'amount' => $split->amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            )->all());
        });

        return true;
    }

    private function hasSplits(Transaction $transaction): bool
    {
        return $transaction->splits()->exists();
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
