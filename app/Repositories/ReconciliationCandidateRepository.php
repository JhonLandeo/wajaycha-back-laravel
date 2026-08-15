<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\ReconciliationKind;
use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Repositories\Contracts\ReconciliationCandidateRepositoryContract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReconciliationCandidateRepository implements ReconciliationCandidateRepositoryContract
{
    public function unpairedMatchesWithin(Transaction $transaction, int $windowHours, int $limit): mixed
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
            return new Collection;
        }

        $operatedAt = Carbon::parse($transaction->date_operation);

        return $this->eligibleCounterparts($transaction)
            ->whereBetween('date_operation', [
                $operatedAt->copy()->subHours($windowHours),
                $operatedAt->copy()->addHours($windowHours),
            ])
            ->orderByRaw('ABS(EXTRACT(EPOCH FROM (date_operation - ?)))', [$operatedAt->toDateTimeString()])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function unpairedSameDayMatch(Transaction $transaction): ?Transaction
    {
        if ($this->hasOpenPair($transaction)) {
            return null;
        }

        // El dia se recorta en la zona horaria de la aplicacion y no en UTC: el
        // importador de PDF escribe la fecha del extracto sin hora, asi que esa
        // medianoche es medianoche de Lima. Recortar en UTC correria el limite
        // cinco horas y mandaria las madrugadas al dia anterior.
        $day = Carbon::parse($transaction->date_operation)->timezone(config('app.timezone'));

        return $this->eligibleCounterparts($transaction)
            ->whereBetween('date_operation', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            // El mismo comercio primero. El extracto del BCP nombra a la contraparte
            // de un pago con Yape igual que el Excel — "Yape Yudita Qui" en los dos —
            // y Entity Resolution ya los dejo colgando del MISMO `detail`, asi que la
            // igualdad de `detail_id` es señal directa y no un parecido de texto.
            //
            // Preferido y no exigido: el importador de PDF resuelve su Detail con una
            // igualdad exacta en minusculas mientras el de Yape usa similitud, asi que
            // dos filas del mismo comercio pueden terminar en Details distintos.
            // Exigirlo perderia duplicados verdaderos; preferirlo no cuesta nada.
            //
            // Por `id` despues, que solo aporta reproducibilidad.
            ->orderByRaw('(detail_id = ?) DESC', [$transaction->detail_id])
            ->orderBy('id')
            ->first();
    }

    /**
     * Las filas que podrian ser el mismo movimiento, antes de aplicar ninguna regla
     * de tiempo. Lo comun a las dos preguntas vive aca una sola vez.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function eligibleCounterparts(Transaction $transaction): mixed
    {
        return Transaction::query()
            // `payment_service_id` y `financial_entity_id` viajan porque el detector
            // decide con ellos si el par es estructural — la billetera y el extracto
            // de la misma institucion — y sin ellos volveria a la base por cada fila.
            ->select([
                'id', 'user_id', 'date_operation', 'is_date_estimated', 'source_type',
                'payment_service_id', 'financial_entity_id',
            ])
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
            ->whereNotExists($this->alreadyPairedWith($transaction))
            ->whereNotExists($this->alreadySpokenFor());
    }

    public function open(Transaction $transaction, Transaction $candidate, array $resolution = []): ?ReconciliationCandidate
    {
        try {
            return ReconciliationCandidate::create([
                'user_id' => $transaction->user_id,
                'transaction_id' => $transaction->id,
                'candidate_transaction_id' => $candidate->id,
                // Se deduce de las dos filas y no se recibe: es un hecho sobre el par,
                // no una eleccion de quien lo abre, y un llamador que lo pase mal
                // desactiva la garantia de uno-a-uno sin que nada se queje.
                'kind' => ReconciliationKind::between($transaction->source_type, $candidate->source_type),
                'status' => ReconciliationStatus::PENDING,
                ...$resolution,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos importaciones a la vez pueden elegir el mismo candidato, y ahora
            // tambien el mismo MAESTRO: `unq_reconciliation_candidates_cross_source_master`
            // es lo que cierra esa carrera, que ningun filtro en la consulta podia
            // cerrar porque leer y escribir no eran un solo paso. Los dos indices son
            // arbitros; perder la carrera no es un error.
            return null;
        }
    }

    /**
     * El mismo criterio que {@see alreadySpokenFor()}, aplicado al lado que se
     * inspecciona. Un maestro que ya absorbio un duplicado tampoco sale a buscar otro.
     */
    private function hasOpenPair(Transaction $transaction): bool
    {
        return ReconciliationCandidate::query()
            ->whereIn('status', [ReconciliationStatus::PENDING, ReconciliationStatus::CONFIRMED])
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
     * Excludes a row that already has a pair — resuelta o todavia en pregunta.
     *
     * PENDING estaba cubierto desde el principio: proponer dos veces la misma fila
     * deja que el usuario confirme las dos, y la segunda confirmacion apuntaria un
     * satelite contra una fila que acababa de volverse satelite ella misma.
     *
     * CONFIRMED faltaba, y su ausencia se veia en produccion. `matched_transaction_id`
     * lo lleva el SATELITE, asi que el MAESTRO de un par ya resuelto sigue teniendolo
     * en null y `whereNull('matched_transaction_id')` no lo filtraba: quedaba libre
     * para siempre. Con dos pagos de S/ 5 el mismo dia, los dos movimientos de Yape
     * se colgaron del mismo asiento del banco y el otro asiento se quedo sin pareja,
     * contando solo. El total daba bien de casualidad — dos asientos contando en vez
     * de uno y uno — pero la pantalla mostraba un cobro con dos origenes y otro con
     * ninguno, que es lo que lo delato.
     *
     * Los montos se comparan exactos, asi que un asiento es UN pago: absorbe un
     * duplicado y ni uno mas.
     *
     * REJECTED queda deliberadamente afuera. Que el usuario haya dicho "estos dos son
     * distintos" no inmoviliza a ninguno de los dos: cada uno sigue libre de ser el
     * duplicado de otra fila. Lo que no puede repetirse es ESE par, y de eso se ocupa
     * `alreadyPairedWith()`.
     */
    private function alreadySpokenFor(): callable
    {
        return function (QueryBuilder $query): void {
            $query->select(DB::raw('1'))
                ->from('reconciliation_candidates as rc_open')
                ->whereIn('rc_open.status', [
                    ReconciliationStatus::PENDING->value,
                    ReconciliationStatus::CONFIRMED->value,
                ])
                ->where(function (QueryBuilder $side): void {
                    $side->whereColumn('rc_open.transaction_id', 'transactions.id')
                        ->orWhereColumn('rc_open.candidate_transaction_id', 'transactions.id');
                });
        };
    }
}
