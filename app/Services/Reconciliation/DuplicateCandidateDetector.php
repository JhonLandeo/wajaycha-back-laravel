<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
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
 * Two outcomes, not one. A pair seconds apart is reconciled here and now; a pair
 * that is merely plausible is queued for a person. Where that line falls is the
 * whole design, and {@see AUTO_WINDOW_SECONDS} carries the measurement it came
 * from.
 */
class DuplicateCandidateDetector
{
    /** This pair is never merged without asking, however close the two records sit. */
    public const NO_AUTOMATIC_MERGE = 0;

    /**
     * How close two records must sit before the system merges them on its own,
     * per pair of sources. Keys are the two `source_type` values sorted and
     * joined, so the pair reads the same in either direction.
     *
     * There is one number per pair because the gap measures different physics
     * depending on which doors the movement came through. A capture and a Yape
     * export are both written the moment the payment happens, so a real duplicate
     * shows up seconds apart — the four in the development data sat 6, 15, 33 and
     * 59 seconds apart. A bank statement records when the bank POSTED the
     * movement, which is not when it was paid: across the 3010 reconciled pairs in
     * `wajaycha_audit`, every one of them app-against-statement, the gap averages
     * 11.6 hours and reaches 45.
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
     * An unlisted pair falls to NO_AUTOMATIC_MERGE. A new source has no measured
     * behaviour yet, and the safe reading of no evidence is to ask.
     */
    private const AUTO_WINDOW_SECONDS = [
        'capture:import_app' => 120,
        'import_app:manual' => 120,
        'capture:manual' => 120,

        'capture:import_statement' => self::NO_AUTOMATIC_MERGE,
        'import_app:import_statement' => self::NO_AUTOMATIC_MERGE,
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
        $candidate = $this->candidates->closestUnpairedMatch($transaction, self::WINDOW_HOURS);

        if ($candidate === null) {
            return null;
        }

        return $this->isConclusive($transaction, $candidate)
            ? $this->reconcile($transaction, $candidate)
            : $this->ask($transaction, $candidate);
    }

    /**
     * Whether proximity alone settles it.
     *
     * Proximity is the only signal, so it has to be a real one. A capture whose
     * date Gemini could not read carries the moment the photo was sent, and two
     * such timestamps landing seconds apart says something about when the user
     * opened Telegram and nothing about when the money moved. Those pairs are
     * still worth asking about — they are just not worth deciding alone.
     */
    private function isConclusive(Transaction $transaction, Transaction $candidate): bool
    {
        $window = $this->autoWindowFor($transaction->source_type, $candidate->source_type);

        if ($window === self::NO_AUTOMATIC_MERGE) {
            return false;
        }

        if ($transaction->is_date_estimated || $candidate->is_date_estimated) {
            return false;
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
            $record = $this->candidates->open($transaction, $candidate, [
                'status' => ReconciliationStatus::CONFIRMED,
                'resolved_by' => ResolvedBy::SYSTEM,
                'resolved_at' => now(),
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
