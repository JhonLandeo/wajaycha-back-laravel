<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Repositories\Contracts\ReconciliationCandidateRepositoryContract;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReconciliationCandidateRepository implements ReconciliationCandidateRepositoryContract
{
    public function closestUnpairedMatch(Transaction $transaction, int $windowHours): ?Transaction
    {
        // La fila que se inspecciona tampoco puede estar ya en un par abierto.
        // `alreadyAwaitingADecision()` cubre al candidato pero no a este lado, y sin
        // esto una misma fila puede quedar en dos pares: confirmar ambos la deja
        // siendo satelite de uno y maestro del otro, y como `fn_get_transactions`
        // solo mira `matched_transaction_id IS NULL`, el satelite del medio se
        // descuenta y el movimiento del extremo desaparece de los totales por
        // completo. En produccion `inspect()` corre sobre una fila recien creada,
        // asi que la cadena no se forma sola — pero la integridad no deberia
        // depender de en que orden llame el que llama.
        if ($this->hasOpenPair($transaction)) {
            return null;
        }

        $operatedAt = Carbon::parse($transaction->date_operation);

        return Transaction::query()
            ->select(['id', 'user_id', 'date_operation', 'is_date_estimated', 'source_type'])
            ->where('user_id', $transaction->user_id)
            ->whereKeyNot($transaction->id)
            ->where('type_transaction', $transaction->type_transaction)
            ->where('amount', $transaction->amount)
            // Solo cruzamos puertas distintas. Dos filas de la misma fuente que se
            // repiten son un problema de esa fuente, y cada importador ya lo cubre.
            ->where('source_type', '!=', $transaction->source_type)
            // Una fila ya conciliada dejo de contar: proponerla otra vez ofreceria
            // descontar un movimiento que ya no esta en ningun total.
            ->whereNull('matched_transaction_id')
            ->whereBetween('date_operation', [
                $operatedAt->copy()->subHours($windowHours),
                $operatedAt->copy()->addHours($windowHours),
            ])
            ->whereNotExists($this->alreadyPairedWith($transaction))
            ->whereNotExists($this->alreadyAwaitingADecision())
            ->orderByRaw('ABS(EXTRACT(EPOCH FROM (date_operation - ?)))', [$operatedAt->toDateTimeString()])
            ->orderBy('id')
            ->first();
    }

    public function open(Transaction $transaction, Transaction $candidate, array $resolution = []): ?ReconciliationCandidate
    {
        try {
            return ReconciliationCandidate::create([
                'user_id' => $transaction->user_id,
                'transaction_id' => $transaction->id,
                'candidate_transaction_id' => $candidate->id,
                'status' => ReconciliationStatus::PENDING,
                ...$resolution,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos importaciones a la vez pueden elegir el mismo candidato. El indice
            // normalizado del par es el arbitro; perder la carrera no es un error.
            return null;
        }
    }

    private function hasOpenPair(Transaction $transaction): bool
    {
        return ReconciliationCandidate::query()
            ->where('status', ReconciliationStatus::PENDING)
            ->where(function ($side) use ($transaction): void {
                $side->where('transaction_id', $transaction->id)
                    ->orWhere('candidate_transaction_id', $transaction->id);
            })
            ->exists();
    }

    public function autoMergedFor(int $userId, int $limit): mixed
    {
        return ReconciliationCandidate::query()
            ->where('user_id', $userId)
            ->where('status', ReconciliationStatus::CONFIRMED)
            ->where('resolved_by', ResolvedBy::SYSTEM)
            ->with([
                'transaction.detail:id,description',
                'candidateTransaction.detail:id,description',
            ])
            ->orderByDesc('resolved_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Excludes a row this transaction was already paired with, whatever the user
     * decided. A rejection is a permanent answer — without this the next import
     * asks again about the pair the user already separated.
     */
    private function alreadyPairedWith(Transaction $transaction): callable
    {
        return function (QueryBuilder $query) use ($transaction): void {
            $query->select(DB::raw('1'))
                ->from('reconciliation_candidates as rc')
                ->where(function (QueryBuilder $pair) use ($transaction): void {
                    $pair->where('rc.transaction_id', $transaction->id)
                        ->whereColumn('rc.candidate_transaction_id', 'transactions.id');
                })
                ->orWhere(function (QueryBuilder $pair) use ($transaction): void {
                    $pair->where('rc.candidate_transaction_id', $transaction->id)
                        ->whereColumn('rc.transaction_id', 'transactions.id');
                });
        };
    }

    /**
     * Excludes a row that is already the subject of an open question. Proposing it
     * twice would let a user confirm both, and the second confirmation would point
     * a satellite at a row that had itself just become one.
     */
    private function alreadyAwaitingADecision(): callable
    {
        return function (QueryBuilder $query): void {
            $query->select(DB::raw('1'))
                ->from('reconciliation_candidates as rc_open')
                ->where('rc_open.status', ReconciliationStatus::PENDING->value)
                ->where(function (QueryBuilder $side): void {
                    $side->whereColumn('rc_open.transaction_id', 'transactions.id')
                        ->orWhereColumn('rc_open.candidate_transaction_id', 'transactions.id');
                });
        };
    }
}
