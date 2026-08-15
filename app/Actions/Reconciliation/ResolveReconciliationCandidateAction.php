<?php

declare(strict_types=1);

namespace App\Actions\Reconciliation;

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Services\Reconciliation\ReconciliationLinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Applies a person's answer to a suspected duplicate — including an answer that
 * overrules what the system already did.
 *
 * Confirming does not delete anything. The two rows are two witnesses of one
 * event, and the photo the user sent is the reason they trust the number at all;
 * dropping it to fix a total destroys the evidence to save a row. What changes is
 * which of the two is counted — `matched_transaction_id`, the same mechanism the
 * unification migration already uses and that `fn_get_transactions` already
 * honours.
 */
class ResolveReconciliationCandidateAction
{
    public function __construct(
        private readonly ReconciliationLinker $linker
    ) {}

    public function confirm(ReconciliationCandidate $candidate): ReconciliationCandidate
    {
        $this->refuseUnless($candidate, ReconciliationStatus::PENDING);

        return DB::transaction(function () use ($candidate): ReconciliationCandidate {
            [$a, $b] = $this->lockBothSides($candidate);

            [$master, $satellite] = $this->linker->link($a, $b);

            // `master_transaction_id` va junto con el estado y no despues: el CHECK
            // exige que existan o falten a la vez, y es lo que hace que el indice
            // unico alcance tambien al camino del usuario. Confirmar a mano dos pares
            // contra el mismo asiento choca aca igual que lo haria el detector.
            $candidate->update([
                'status' => ReconciliationStatus::CONFIRMED,
                'resolved_by' => ResolvedBy::USER,
                'resolved_at' => now(),
                'master_transaction_id' => $master->id,
            ]);

            Log::info("🔗 Reconciliación confirmada: transacción {$satellite->id} deja de contar,"
                ." conciliada contra {$master->id}.");

            return $candidate->refresh();
        });
    }

    public function reject(ReconciliationCandidate $candidate): ReconciliationCandidate
    {
        $this->refuseUnless($candidate, ReconciliationStatus::PENDING);

        $candidate->update([
            'status' => ReconciliationStatus::REJECTED,
            'resolved_by' => ResolvedBy::USER,
            'resolved_at' => now(),
        ]);

        Log::info("✂️ Reconciliación descartada: {$candidate->transaction_id} y "
            ."{$candidate->candidate_transaction_id} son movimientos distintos.");

        return $candidate->refresh();
    }

    /**
     * Puts a pair the system merged back into the totals.
     *
     * This is what earns the system the right to decide alone. The result is a
     * rejection, not a return to pending: the user has now answered the question,
     * and the permanence of that answer is what keeps the next import from
     * proposing — or re-merging — the pair it just got wrong.
     */
    public function undo(ReconciliationCandidate $candidate): ReconciliationCandidate
    {
        $this->refuseUnless($candidate, ReconciliationStatus::CONFIRMED);

        if ($candidate->resolved_by !== ResolvedBy::SYSTEM) {
            // Deshacer lo que uno mismo confirmo es otra operacion, con otra
            // pregunta detras. Esta existe para corregir al sistema.
            throw new RuntimeException('Esa unificación no la hizo el sistema.');
        }

        return DB::transaction(function () use ($candidate): ReconciliationCandidate {
            [$a, $b] = $this->lockBothSides($candidate);

            $this->linker->unlink($a, $b);

            // El maestro se va con la unificacion. Dejarlo apuntando a una fila que
            // volvio a contar sola seria un descuento registrado que ya no existe, y
            // ademas bloquearia ese asiento para el par correcto.
            $candidate->update([
                'status' => ReconciliationStatus::REJECTED,
                'resolved_by' => ResolvedBy::USER,
                'resolved_at' => now(),
                'master_transaction_id' => null,
            ]);

            Log::info("↩️ Unificación automática deshecha: {$candidate->transaction_id} y "
                ."{$candidate->candidate_transaction_id} vuelven a contar por separado.");

            return $candidate->refresh();
        });
    }

    /** @return array{0: Transaction, 1: Transaction} */
    private function lockBothSides(ReconciliationCandidate $candidate): array
    {
        /** @var Transaction $a */
        $a = $candidate->transaction()->lockForUpdate()->firstOrFail();
        /** @var Transaction $b */
        $b = $candidate->candidateTransaction()->lockForUpdate()->firstOrFail();

        return [$a, $b];
    }

    /**
     * Guards against re-settling. Confirming something already rejected would
     * silently remove a movement the user had explicitly kept.
     */
    private function refuseUnless(ReconciliationCandidate $candidate, ReconciliationStatus $expected): void
    {
        if ($candidate->status !== $expected) {
            throw new RuntimeException('La sospecha ya fue resuelta.');
        }
    }
}
