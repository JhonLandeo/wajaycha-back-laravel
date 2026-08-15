<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\BlindnessObservation;
use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\ClaimAttempt;
use App\DTOs\Coaching\CoachingCause;
use App\DTOs\Coaching\CoachingScope;
use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceObservation;
use App\DTOs\Coaching\PaceThresholds;
use App\Models\CoachingObservation;
use App\Models\User;
use App\Repositories\Contracts\CategoryRepositoryContract;
use App\Repositories\Contracts\TransactionRepositoryContract;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use Carbon\CarbonImmutable;

/**
 * The one decider both entry points share (design.md §1/§2). The scheduled
 * sweep (`RunCoachingSweep`) and the capture-time check (`ProcessTelegramCapture`,
 * phase 5 — not built by this batch) call {@see speak()} and differ only in the
 * `CoachingScope` they pass. "One voice" is structural: there is no second
 * evaluation path to drift from this one.
 *
 * Sequencing per design.md §5.2/§5.3: snapshot -> evaluate -> ladder -> cause ->
 * claim -> compose -> send -> confirm. `claim()` ALWAYS runs before `reply()`
 * (D7) — the insert must precede the send for the ledger's unique index to be
 * able to arbitrate a sweep/capture race at all.
 *
 * `config('coaching.enabled')` is checked as the FIRST line of both public
 * methods (design.md "Rollback ladder", item 1): a disabled coach never even
 * queries a category snapshot.
 */
class FinancialCoachingService
{
    /**
     * Mirrors `SpokenObservationLedger::BAND_SEVERITY` (private there, no
     * public accessor existed before this batch). The ladder decision — "never
     * speak downward" — belongs to this service per design.md §5.2's diagram
     * ("Note over Coach: descarta lo que no cruza a una banda peor"), not to
     * the ledger, which only arbitrates the exact (user, period, subject, band)
     * tuple via its unique index. Kept in sync deliberately rather than adding
     * a public accessor to already-shipped, already-tested Phase 3 code for a
     * single caller.
     */
    private const BAND_SEVERITY = [
        'projected_over' => 1,
        'over_budget' => 2,
        // The envelope family reuses ranks 1 and 2 rather than extending the
        // ladder. This map is only ever consulted per subject_key, and a
        // category has exactly one budget_period, so the two families never meet
        // on the same subject. The one case that can mix them — the owner
        // switching a category from monthly to yearly mid-month — still behaves:
        // "already past the line" outranks "heading for it" in either family.
        'envelope_consumed' => 1,
        'envelope_exceeded' => 2,
    ];

    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly TransactionRepositoryContract $transactions,
        private readonly PaceEvaluator $evaluator,
        private readonly BlindnessDetector $blindnessDetector,
        private readonly SpokenObservationLedger $ledger,
        private readonly CoachingMessageComposer $composer,
        private readonly ChannelIdentityResolver $identities,
        private readonly CaptureChannelRegistry $channels,
    ) {}

    /**
     * Claims and sends. Returns `true` only when a message was actually
     * delivered to the channel adapter; `false` for every silent outcome —
     * disabled, nothing crosses the speaking threshold, everything was already
     * spoken this month, or the user has no reachable channel identity.
     *
     * There is deliberately no "todo bien" message (design.md §6 rule 6):
     * silence is a first-class outcome, not an error.
     */
    public function speak(User $user, CoachingScope $scope): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $evaluation = $this->evaluateScope($user, $scope);

        if ($evaluation === null) {
            return false;
        }

        [$survivors, $blindness, $cursor] = $evaluation;
        $entryPoint = $scope->isSweep ? 'sweep' : 'capture';

        $claimedObservations = [];
        $claimedIds = [];

        foreach ($survivors as $observation) {
            $cause = $this->causeFor($user->id, $observation, $cursor);
            $attempt = $this->claimAttemptFor($user->id, $cursor, $observation, $entryPoint);

            // Cause query, THEN claim (design.md §5.2): the cause is only
            // computed for observations that survived the ladder, never once
            // per category.
            if (! $this->ledger->claim($attempt)) {
                // Otra voz (barrido o captura) ya reclamo esta banda exacta. El
                // indice unico ya arbitro; aqui solo se queda en silencio.
                continue;
            }

            $claimedIds[] = $this->observationId($user->id, $cursor, $observation->subjectKey, $observation->band);
            $claimedObservations[] = $this->withCause($observation, $cause);
        }

        $blindnessClaimed = false;
        if ($blindness !== null) {
            $attempt = $this->blindnessClaimAttempt($user->id, $cursor, $blindness);

            if ($this->ledger->claim($attempt)) {
                $blindnessClaimed = true;
                $claimedIds[] = $this->observationId($user->id, $cursor, 'blindness', 'blind');
            }
        }

        if ($claimedObservations === [] && ! $blindnessClaimed) {
            // Race lost against another voice for every candidate: everything
            // that was worth saying was already said by whoever claimed first.
            return false;
        }

        $identity = $this->identities->preferredIdentityFor($user->id, (array) config('coaching.channels'));

        if ($identity === null) {
            // Defensive only: both callers are expected to filter by
            // userIdsReachableOn() before ever invoking speak(). If reached,
            // the claimed rows stay spoken_at-set/sent_at-NULL, the same
            // operator-queryable signal design.md §7 describes for a worker
            // dying mid-flight.
            return false;
        }

        $message = $this->composer->compose($claimedObservations, $blindnessClaimed ? $blindness : null);

        $this->channels->for($identity->channel)->reply($identity->external_id, $message);

        // D7: sent_at only proves reply() returned without throwing — the
        // untouched TelegramChannel::reply() logs and swallows a non-2xx
        // response, so this is unconditional once the call returns, not proof
        // of delivery (design.md §7's failure table).
        $this->ledger->confirmSent(array_values(array_filter($claimedIds, fn (?int $id) => $id !== null)));

        return true;
    }

    /**
     * Composes what `speak()` WOULD say, without claiming anything and without
     * sending anything (design.md §12, rollout step 2: "--dry-run must skip
     * the claim, otherwise it burns the bands it was meant to preview").
     *
     * This is a second public method on a service design.md §1 describes as
     * exposing "exactly one" — a deliberate, narrow deviation. Duplicating the
     * evaluate/ladder/cause pipeline inside `RunCoachingSweep` instead would
     * put band/threshold logic in a Command, which `.agents/rules/01-laravel-core.md`
     * and design.md §1 itself both forbid ("the Command orchestrates; it holds
     * no threshold, no band, no string"). Flagged explicitly in the apply
     * return summary rather than silently added.
     */
    public function preview(User $user, CoachingScope $scope): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $evaluation = $this->evaluateScope($user, $scope);

        if ($evaluation === null) {
            return null;
        }

        [$survivors, $blindness, $cursor] = $evaluation;

        $withCauses = array_map(
            fn (PaceObservation $observation): PaceObservation => $this->withCause(
                $observation,
                $this->causeFor($user->id, $observation, $cursor),
            ),
            $survivors,
        );

        return $this->composer->compose($withCauses, $blindness);
    }

    private function enabled(): bool
    {
        return (bool) config('coaching.enabled');
    }

    /**
     * Snapshot -> evaluate -> ladder, shared by `speak()` and `preview()`.
     * Returns null when there is nothing worth claiming or composing.
     *
     * @return array{0: PaceObservation[], 1: ?BlindnessObservation, 2: MonthCursor}|null
     */
    private function evaluateScope(User $user, CoachingScope $scope): ?array
    {
        $cursor = $this->cursor();
        $thresholds = $this->thresholds();

        $snapshots = $this->categories->expenseBudgetSnapshotsForMonth(
            $user->id,
            $cursor->periodMonth->month,
            $cursor->periodMonth->year,
        );

        $candidates = $scope->isSweep
            ? $this->evaluator->evaluate($snapshots, $cursor, $thresholds)
            : $this->evaluateCaptureScope($scope, $snapshots, $cursor, $thresholds);

        $survivors = array_values(array_filter(
            $candidates,
            fn (PaceObservation $observation): bool => $this->worsens(
                $this->ledger->highestBandFor($user->id, $cursor->periodMonth, $observation->subjectKey),
                $observation->band,
            ),
        ));

        $blindness = null;
        if ($scope->isSweep) {
            $detected = $this->blindnessDetector->detect($snapshots);

            // Blindness is not on the pace ladder (design.md §5.1: "no
            // comparable") — it only ever speaks once per user per month.
            if ($detected !== null && $this->ledger->highestBandFor($user->id, $cursor->periodMonth, 'blindness') === null) {
                $blindness = $detected;
            }
        }

        if ($survivors === [] && $blindness === null) {
            return null;
        }

        return [$survivors, $blindness, $cursor];
    }

    /**
     * The capture-time counterfactual (design.md §5.3): evaluate the category
     * with the just-registered transaction, then again with `spent - amount`
     * (only `spent` varies — the design's own sequence diagram does not
     * recompute lumpiness for the counterfactual). Speaks only when the band
     * with the transaction is worse than the band without it — "only if that
     * transaction crossed the line" is this comparison, not a second rule.
     *
     * @param  CategoryMonthSnapshot[]  $snapshots
     * @return PaceObservation[]
     */
    private function evaluateCaptureScope(
        CoachingScope $scope,
        array $snapshots,
        MonthCursor $cursor,
        PaceThresholds $thresholds,
    ): array {
        $snapshot = $this->findSnapshot($snapshots, $scope->categoryId);

        if ($snapshot === null) {
            return [];
        }

        $withObservations = $this->evaluator->evaluate([$snapshot], $cursor, $thresholds);
        $withBand = $withObservations[0]->band ?? null;

        if ($withBand === null) {
            return [];
        }

        $amount = $scope->amount ?? 0.0;

        // `budgetPeriod` has to be carried through: rebuilt without it the
        // counterfactual would default to monthly, so a yearly category would be
        // compared against the envelope table with the transaction and against
        // the pace table without it — two different questions, and the
        // comparison of their answers would be meaningless.
        //
        // `spentInYear` drops by the same amount for the same reason `spent`
        // does: the counterfactual asks "what would the band be if this
        // transaction had not happened", and it did not happen in the year
        // either.
        $withoutSnapshot = new CategoryMonthSnapshot(
            categoryId: $snapshot->categoryId,
            name: $snapshot->name,
            type: $snapshot->type,
            monthlyBudget: $snapshot->monthlyBudget,
            spent: $snapshot->spent - $amount,
            largestExpenseAmount: $snapshot->largestExpenseAmount,
            budgetPeriod: $snapshot->budgetPeriod,
            spentInYear: $snapshot->spentInYear - $amount,
        );

        $withoutBand = $this->evaluator->evaluate([$withoutSnapshot], $cursor, $thresholds)[0]->band ?? null;

        return $this->worsens($withoutBand, $withBand) ? $withObservations : [];
    }

    /** @param  CategoryMonthSnapshot[]  $snapshots */
    private function findSnapshot(array $snapshots, ?int $categoryId): ?CategoryMonthSnapshot
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->categoryId === $categoryId) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * Whether `$newBand` is worse than `$currentHighest` — null means nothing
     * was ever spoken, which always warrants speaking. `blind` never reaches
     * this method (handled separately: "already spoken this month or not").
     */
    private function worsens(?string $currentHighest, string $newBand): bool
    {
        if ($currentHighest === null) {
            return true;
        }

        return (self::BAND_SEVERITY[$newBand] ?? 0) > (self::BAND_SEVERITY[$currentHighest] ?? 0);
    }

    /**
     * D5: cause queries run only for observations that survived the evaluator
     * and the band ladder — at most 3 categories per user per run, never once
     * per category. Returns null (never fabricated) when the cause query
     * genuinely finds nothing, which D9 makes structurally near-impossible
     * whenever `spent > 0`.
     */
    private function causeFor(int $userId, PaceObservation $observation, MonthCursor $cursor): ?CoachingCause
    {
        // An envelope takes the single-largest-expense branch for the same
        // reason a lumpy month does: one purchase is what moved it. Asking for a
        // merchant breakdown with a frequency would describe a rate the category
        // does not have. The window stays the month — "what moved this" is a
        // question about what just happened, not about the whole year.
        if ($observation->isLumpy || $observation->isEnvelope()) {
            $row = $this->transactions->largestExpenseForCategoryMonth(
                $userId,
                $observation->categoryId,
                $cursor->startsAt,
                $cursor->endsAt,
            );

            if ($row === null) {
                return null;
            }

            return new CoachingCause(
                merchantName: (string) $row->detail_name,
                amount: (float) $row->amount,
                // Unused by the lumpy shape (App\DTOs\Coaching\CoachingCause
                // docblock); a single dominant transaction has no frequency to
                // report.
                frequency: 1,
                transactionDate: CarbonImmutable::parse((string) $row->date_operation),
            );
        }

        $topMerchants = $this->transactions->topMerchantsForCategoryMonth(
            $userId,
            $observation->categoryId,
            $cursor->startsAt,
            $cursor->endsAt,
            1,
        );
        $top = $topMerchants[0] ?? null;

        if ($top === null) {
            return null;
        }

        return new CoachingCause(
            merchantName: (string) $top->detail_name,
            amount: (float) $top->total,
            frequency: (int) $top->frequency,
        );
    }

    /** PaceObservation is readonly; this rebuilds one with `cause` filled in. */
    private function withCause(PaceObservation $observation, ?CoachingCause $cause): PaceObservation
    {
        return new PaceObservation(
            subjectKey: $observation->subjectKey,
            categoryId: $observation->categoryId,
            name: $observation->name,
            band: $observation->band,
            isLumpy: $observation->isLumpy,
            spent: $observation->spent,
            budget: $observation->budget,
            projected: $observation->projected,
            dayOfMonth: $observation->dayOfMonth,
            cause: $cause,
        );
    }

    private function claimAttemptFor(int $userId, MonthCursor $cursor, PaceObservation $observation, string $entryPoint): ClaimAttempt
    {
        return new ClaimAttempt(
            userId: $userId,
            periodMonth: $cursor->periodMonth,
            subjectKey: $observation->subjectKey,
            band: $observation->band,
            categoryId: $observation->categoryId,
            isLumpy: $observation->isLumpy,
            budgetAmount: $observation->budget,
            spentAmount: $observation->spent,
            projectedAmount: $observation->projected,
            dayOfMonth: $observation->dayOfMonth,
            entryPoint: $entryPoint,
        );
    }

    private function blindnessClaimAttempt(int $userId, MonthCursor $cursor, BlindnessObservation $blindness): ClaimAttempt
    {
        return new ClaimAttempt(
            userId: $userId,
            periodMonth: $cursor->periodMonth,
            subjectKey: 'blindness',
            band: 'blind',
            categoryId: null,
            isLumpy: false,
            budgetAmount: 0.0,
            spentAmount: $blindness->totalSpent,
            projectedAmount: null,
            dayOfMonth: $cursor->day,
            entryPoint: 'sweep',
        );
    }

    /**
     * `SpokenObservationLedger::claim()` returns only a bool (Phase 3,
     * already shipped and tested); confirmSent() needs the row id. Rather
     * than widening claim()'s return type and touching Phase 3's already-green
     * test suite for a need that only exists in this batch, the id is
     * re-fetched by the exact unique-index tuple, which is guaranteed to match
     * at most one row.
     */
    private function observationId(int $userId, MonthCursor $cursor, string $subjectKey, string $band): ?int
    {
        /** @var int|null $id */
        $id = CoachingObservation::query()
            ->where('user_id', $userId)
            ->where('period_month', $cursor->periodMonth->toDateString())
            ->where('subject_key', $subjectKey)
            ->where('band', $band)
            ->value('id');

        return $id;
    }

    private function cursor(): MonthCursor
    {
        return MonthCursor::forInstant(CarbonImmutable::now((string) config('app.timezone')));
    }

    private function thresholds(): PaceThresholds
    {
        return new PaceThresholds(
            minDayForProjection: (int) config('coaching.min_day_for_projection'),
            overrunMargin: (float) config('coaching.overrun_margin'),
            lumpyShare: (float) config('coaching.lumpy_share'),
            maxObservations: (int) config('coaching.max_observations_per_message'),
            envelopeConsumedShare: (float) config('coaching.envelope_consumed_share'),
            envelopeMinMonthsRemaining: (int) config('coaching.envelope_min_months_remaining'),
        );
    }
}
