<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Models\PaymentService;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Repositories\Contracts\ReconciliationCandidateRepositoryContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Looks at a movement that was just written and asks whether the system already
 * knew about it through a different door.
 *
 * The same real payment reaches Wajaycha more than once by design: a photo sent
 * to Telegram the moment it happens, the Yape export weeks later, the bank
 * statement after that. Each door writes its own row, and nothing joined them
 * unless both came from an import.
 *
 * Three questions, asked in order, and only the last one reaches a person.
 *
 * 1. {@see structuralDuplicateOf()} — is this the same movement BY CONSTRUCTION?
 *    A wallet drawn from a card does not produce movements resembling the bank's;
 *    it produces the bank's. Nothing to weigh.
 * 2. {@see isConclusive()} — is proximity enough? Only for doors that both write
 *    at the moment of payment. {@see AUTO_WINDOW_SECONDS} carries the measurement.
 * 3. Otherwise it is queued, and someone decides.
 *
 * The order matters and step 1 is the one that keeps the queue survivable. Before
 * it existed, every same-day pair of equal amounts that had a twin went to a
 * person as "which of these two is it?" — a question with no answer, since either
 * assignment collapses the same money. Measured on four years of real data that
 * was roughly thirteen unanswerable questions a month, forever.
 */
class DuplicateCandidateDetector
{
    /** This pair is never merged without asking, however close the two records sit. */
    public const NO_AUTOMATIC_MERGE = 0;

    /**
     * This pair is merged without asking only when the match is the ONLY one in
     * the window — proximity ranks the candidates but does not separate them.
     *
     * Negative so it can never be read as a number of seconds by accident.
     */
    public const UNIQUE_MATCH_ONLY = -1;

    /**
     * How many candidates are pulled back to answer "is this match alone?".
     *
     * Two, because that is the whole question. A third row would change no
     * decision and the window can hold a week of identical coffees.
     */
    private const MATCHES_EXAMINED = 2;

    /**
     * How close two records must sit before the system merges them on its own,
     * per pair of sources. Keys are the two `source_type` values sorted and
     * joined, so the pair reads the same in either direction.
     *
     * There is one number per pair because the gap measures different physics
     * depending on which doors the movement came through. A capture and a Yape
     * export are both written the moment the payment happens, so a real duplicate
     * shows up seconds apart — the four in the development data sat 6, 15, 33 and
     * 59 seconds apart.
     *
     * A bank statement is a different thing entirely, and an earlier version of
     * this comment got it wrong: it read the 11.6-hour average across the 3010
     * reconciled pairs in `wajaycha_audit` as the delay between paying and the
     * bank posting. It is not. `StatementLineParser` yields a DATE — the BCP PDF
     * carries no time of day — so every statement row lands at midnight and the
     * "gap" is just the hour at which the user happened to pay, measured against
     * a midnight that means nothing. 3590 of the 4089 statement rows there sit on
     * the same clock value.
     *
     * So for any pair involving a statement, proximity is not weak evidence. It
     * is no evidence.
     *
     * Applying one threshold to both was the mistake this table exists to
     * prevent. Two minutes clears the widest true same-instant pair with room to
     * spare, and stays three orders of magnitude below the ten-hour pair that
     * turned out to be two different merchants.
     *
     * A pair worth NO_AUTOMATIC_MERGE is a decision, not a small number: eleven
     * hours apart with only the amount agreeing is thin evidence, and this is
     * money. Today the 120-second bound would exclude those pairs anyway — the
     * entry makes that explicit, so raising a threshold "to merge a few more"
     * cannot silently start merging them.
     *
     * The export against the bank statement barely reaches this table any more.
     * Yape is issued by the BCP — `payment_services.financial_entity_id` says so —
     * so a same-day pair against a BCP statement is settled structurally in step 1
     * and never gets here. What is left for UNIQUE_MATCH_ONLY is the residue: the
     * pair that straddles a day boundary, or a wallet whose issuer is unknown.
     *
     * For that residue solitude is still the only usable signal, and it is a good
     * one. Of the 3010 reconciled pairs in `wajaycha_audit`, 2396 have exactly one
     * candidate of that amount inside the 48-hour window, and for all 2396 the
     * nearest row is the right one — no exceptions. The remaining 614 have two or
     * more, and there the nearest row is right only 421 times.
     *
     * An unlisted pair falls to NO_AUTOMATIC_MERGE. A new source has no measured
     * behaviour yet, and the safe reading of no evidence is to ask.
     */
    private const AUTO_WINDOW_SECONDS = [
        'capture:import_app' => 120,
        'import_app:manual' => 120,
        'capture:manual' => 120,

        'import_app:import_statement' => self::UNIQUE_MATCH_ONLY,

        'capture:import_statement' => self::NO_AUTOMATIC_MERGE,
        'import_statement:manual' => self::NO_AUTOMATIC_MERGE,
    ];

    /**
     * How far apart two records may sit and still be worth asking about.
     *
     * Wider than any automatic bound on purpose: past the automatic window the
     * evidence stops being conclusive but does not become worthless, and a pair
     * eleven hours apart is exactly what a person settles in one glance and an
     * algorithm gets wrong.
     *
     * Forty-eight and not twenty-four because six of the 3010 reconciled pairs in
     * `wajaycha_audit` sit beyond a day, the widest at 45 hours; at 24 they are
     * not merely un-merged, they are invisible. Measured before widening: over the
     * unreconciled rows there, 24 hours proposes 52 pairs and 48 proposes 59.
     * Seven more questions across four years to stop missing them.
     */
    public const WINDOW_HOURS = 48;

    /** @var array<int, int|null> */
    private array $issuers = [];

    public function __construct(
        private readonly ReconciliationCandidateRepositoryContract $candidates,
        private readonly ReconciliationLinker $linker
    ) {}

    /**
     * Records at most one finding for `$transaction`, resolved or pending.
     *
     * One, not all. A user who spends S/ 12 on coffee every morning has a week of
     * identical amounts inside the asking window, and proposing each of them turns
     * a safety feature into a queue of noise nobody reads. The nearest in time is
     * both the likeliest match and the only one a person can judge without
     * reconstructing the week.
     */
    public function inspect(Transaction $transaction): ?ReconciliationCandidate
    {
        $structural = $this->structuralDuplicateOf($transaction);

        if ($structural !== null) {
            return $this->reconcile($transaction, $structural);
        }

        $matches = $this->candidates->unpairedMatchesWithin($transaction, self::WINDOW_HOURS, self::MATCHES_EXAMINED);

        /** @var Transaction|null $candidate */
        $candidate = $matches->first();

        if ($candidate === null) {
            return null;
        }

        return $this->isConclusive($transaction, $candidate, $matches->count() === 1)
            ? $this->reconcile($transaction, $candidate)
            : $this->ask($transaction, $candidate);
    }

    /**
     * La fila que es el mismo movimiento POR CONSTRUCCION, no por parecido.
     *
     * Una billetera descontada de una tarjeta no produce movimientos parecidos a
     * los del banco: produce LOS MISMOS. `payment_services.financial_entity_id`
     * ya lo dice — Yape es del BCP — asi que cuando la fila de la billetera y la
     * del extracto de esa misma institucion coinciden en usuario, monto, sentido y
     * dia, no hay una sospecha que evaluar. Hay un hecho.
     *
     * Y por eso no se pregunta CUAL con cual. El extracto no trae hora, asi que
     * dos filas de S/ 5 del mismo dia son indistinguibles: cualquier asignacion
     * colapsa la misma plata y deja el mismo total, con las dos descripciones
     * conservadas. Preguntarlo era pedirle a la persona que adivinara algo que no
     * cambia nada — y en los datos reales fue exactamente lo que la hizo contestar
     * "son distintos" sobre dos duplicados verdaderos, que siguieron contando dos
     * veces.
     *
     * Lo que sobra del dia no se pregunta tampoco. Un pago hecho con SALDO de la
     * billetera nunca toca la tarjeta y por lo tanto nunca aparece en el extracto:
     * su ausencia no es una duda, es la respuesta. Se queda contando, que es lo
     * correcto.
     *
     * Una fecha estimada rompe todo esto, igual que en el camino por cercania: si
     * el dia mismo es un relleno, coincidir en el dia no dice nada.
     */
    private function structuralDuplicateOf(Transaction $transaction): ?Transaction
    {
        if ($transaction->is_date_estimated) {
            return null;
        }

        $candidate = $this->candidates->unpairedSameDayMatch($transaction);

        if ($candidate === null || $candidate->is_date_estimated) {
            return null;
        }

        return $this->issuedByTheSameInstitution($transaction, $candidate) ? $candidate : null;
    }

    /** Si una de las dos filas salio de una billetera que la otra institucion emite. */
    private function issuedByTheSameInstitution(Transaction $a, Transaction $b): bool
    {
        [$issuer, $institution] = $this->institutionsOf($a, $b);

        return $issuer !== null && $issuer === $institution;
    }

    /**
     * Si sabemos que las dos filas pertenecen a instituciones DISTINTAS.
     *
     * Esto no es lo mismo que "no sabemos". Una billetera que emite el BCP no puede
     * ser el cargo de una tarjeta de otro banco, asi que saber quien emite a cada
     * lado y ver que no coinciden es evidencia EN CONTRA, no evidencia faltante.
     * Sin esta distincion la regla por unicidad unificaba igual: el candidato estaba
     * solo en la ventana, y estar solo no lo convierte en el mismo movimiento.
     */
    private function institutionsDisagree(Transaction $a, Transaction $b): bool
    {
        [$issuer, $institution] = $this->institutionsOf($a, $b);

        return $issuer !== null && $institution !== null && $issuer !== $institution;
    }

    /**
     * El emisor de la billetera y la institucion del asiento, o dos nulls.
     *
     * Pide que exactamente UNA de las dos filas tenga servicio de pago. Dos filas de
     * billetera no son este caso, y dos filas sin billetera tampoco: la relacion que
     * se esta leyendo es "esta billetera la descuenta ese banco", y hace falta un
     * lado de cada uno para tenerla.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function institutionsOf(Transaction $a, Transaction $b): array
    {
        [$wallet, $ledger] = $a->payment_service_id !== null ? [$a, $b] : [$b, $a];

        if ($wallet->payment_service_id === null || $ledger->payment_service_id !== null) {
            return [null, null];
        }

        $issuer = $this->issuerOf((int) $wallet->payment_service_id);
        $institution = $ledger->financial_entity_id;

        return [
            $issuer === null ? null : (int) $issuer,
            $institution === null ? null : (int) $institution,
        ];
    }

    /**
     * La institucion que emite un servicio de pago, memoizada.
     *
     * El catalogo tiene un puñado de filas y esto se pregunta una vez por linea del
     * extracto; sin la memoria seria una consulta por movimiento importado.
     */
    private function issuerOf(int $paymentServiceId): ?int
    {
        if (! array_key_exists($paymentServiceId, $this->issuers)) {
            $this->issuers[$paymentServiceId] = PaymentService::query()
                ->whereKey($paymentServiceId)
                ->value('financial_entity_id');
        }

        return $this->issuers[$paymentServiceId];
    }

    /**
     * Whether the evidence settles it without asking.
     *
     * Two different kinds of evidence, one per kind of pair. Where both doors write
     * at the moment of payment, proximity settles it and `$isAlone` is irrelevant.
     * Where one door is the bank posting hours later, proximity only ranks and
     * solitude settles it — see {@see AUTO_WINDOW_SECONDS} for the measurement
     * behind each.
     *
     * Whichever applies, an estimated date disqualifies the pair. A capture whose
     * date Gemini could not read carries the moment the photo was sent, and two
     * such timestamps landing seconds apart says something about when the user
     * opened Telegram and nothing about when the money moved. Those pairs are
     * still worth asking about — they are just not worth deciding alone.
     */
    private function isConclusive(Transaction $transaction, Transaction $candidate, bool $isAlone): bool
    {
        // Saber que son de instituciones distintas gana sobre cualquier umbral: estar
        // solo en la ventana no convierte el cargo de otro banco en este pago.
        if ($this->institutionsDisagree($transaction, $candidate)) {
            return false;
        }

        $window = $this->autoWindowFor($transaction->source_type, $candidate->source_type);

        if ($window === self::NO_AUTOMATIC_MERGE) {
            return false;
        }

        if ($transaction->is_date_estimated || $candidate->is_date_estimated) {
            return false;
        }

        if ($window === self::UNIQUE_MATCH_ONLY) {
            return $isAlone;
        }

        $apart = Carbon::parse($transaction->date_operation)
            ->diffInSeconds(Carbon::parse($candidate->date_operation), absolute: true);

        return $apart <= $window;
    }

    /**
     * The automatic bound for this combination of doors.
     *
     * The key is sorted so a pair reads the same whichever row was written first:
     * which of the two arrived last is an accident of when the user exported
     * their statement, and it must not change what the system is willing to
     * decide.
     */
    private function autoWindowFor(?string $first, ?string $second): int
    {
        $pair = [
            SourceType::fromColumn($first)->value,
            SourceType::fromColumn($second)->value,
        ];

        sort($pair);

        return self::AUTO_WINDOW_SECONDS[implode(':', $pair)] ?? self::NO_AUTOMATIC_MERGE;
    }

    /**
     * Merges the pair without asking, and leaves the record that says so.
     *
     * The candidate row is written even though nobody will answer it. It is what
     * makes the decision visible and undoable, which is the only reason deciding
     * alone is acceptable at all: nothing disappears from a total in silence.
     */
    private function reconcile(Transaction $transaction, Transaction $candidate): ?ReconciliationCandidate
    {
        return DB::transaction(function () use ($transaction, $candidate): ?ReconciliationCandidate {
            // Se decide el maestro ANTES de abrir el par, y no despues, porque es la
            // fila que el indice unico arbitra: si otro proceso ya se colgo de ese
            // asiento, el insert falla y aca no se enlaza nada. Al reves — enlazar y
            // despues registrar — el descuento ya estaria hecho cuando llega el error.
            [$master] = $this->linker->rank($transaction, $candidate);

            $record = $this->candidates->open($transaction, $candidate, [
                'status' => ReconciliationStatus::CONFIRMED,
                'resolved_by' => ResolvedBy::SYSTEM,
                'resolved_at' => now(),
                'master_transaction_id' => $master->id,
            ]);

            if ($record === null) {
                return null;
            }

            [$master, $satellite] = $this->linker->link($transaction, $candidate);

            Log::info("🔗 Reconciliación automática: transacción {$satellite->id} deja de contar,"
                ." unificada con {$master->id} (usuario {$transaction->user_id}).");

            return $record;
        });
    }

    private function ask(Transaction $transaction, Transaction $candidate): ?ReconciliationCandidate
    {
        $record = $this->candidates->open($transaction, $candidate);

        if ($record === null) {
            return null;
        }

        Log::info("🔎 Reconciliación: transacción {$transaction->id} podría duplicar a {$candidate->id}"
            ." (usuario {$transaction->user_id}); queda pendiente de confirmación.");

        return $record;
    }
}
