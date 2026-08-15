<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ReconciliationCandidate;
use App\Models\Transaction;

interface ReconciliationCandidateRepositoryContract
{
    /**
     * The rows that could be the same real movement as `$transaction`, nearest in
     * time first, capped at `$limit`.
     *
     * Returns a list rather than the single closest row because how ALONE a match
     * is turned out to be evidence in its own right. Where the gap between two
     * doors is hours wide — an export against a bank statement — proximity ranks
     * the candidates but cannot separate them, and being the only row of that
     * amount in the window is what does. The caller decides what to make of that;
     * what lives here is how to ask it of PostgreSQL.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Transaction>
     */
    public function unpairedMatchesWithin(Transaction $transaction, int $windowHours, int $limit): mixed;

    /**
     * La fila libre del mismo dia calendario que mejor explica a `$transaction`,
     * repitiendo monto y sentido.
     *
     * NO por cercania temporal: el extracto del banco no trae hora del dia, asi que
     * dos filas del mismo dia no se ordenan en el tiempo de ninguna manera honesta.
     * Se ordena por comercio — mismo `detail_id` primero — y recien despues por `id`.
     *
     * Una version anterior de esto ordenaba solo por `id`, sobre la idea de que dos
     * filas del mismo dia y monto son intercambiables porque cualquier asignacion da
     * el mismo total. El total si, pero la pantalla no: con dos cobros de S/ 5 el
     * mismo dia, el asiento de "Yape Yudita Qui" quedo explicado por el pago a "Yape
     * Andi Cuy" y viceversa. El usuario lo vio de inmediato, y tenia razon — el
     * comercio SI distingue, porque el extracto lo nombra igual que el Excel.
     *
     * El dia se recorta en la zona horaria de la aplicacion, que es donde el
     * extracto puso su medianoche.
     */
    public function unpairedSameDayMatch(Transaction $transaction): ?Transaction;

    /**
     * Records the finding, or null if the pair was claimed concurrently.
     *
     * `$resolution` overrides the pending defaults for a pair the system settled
     * on its own — status, who decided, and when.
     *
     * @param  array<string, mixed>  $resolution
     */
    public function open(Transaction $transaction, Transaction $candidate, array $resolution = []): ?ReconciliationCandidate;

    /**
     * Pairs the system merged on its own and the user has not reviewed, newest
     * first — the list that keeps an automatic decision from being a silent one.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ReconciliationCandidate>
     */
    public function autoMergedFor(int $userId, int $limit): mixed;
}
