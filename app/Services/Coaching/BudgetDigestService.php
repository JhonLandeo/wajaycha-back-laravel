<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceThresholds;
use App\Models\User;
use App\Repositories\Contracts\CategoryRepositoryContract;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use Carbon\CarbonImmutable;

/**
 * Sends the morning budget digest — the standing answer to "what have I already
 * blown, and what am I about to".
 *
 * Deliberately NOT a method on {@see FinancialCoachingService}. That service owns
 * a specific contract — claim before send, never repeat a band, never speak
 * downward — and every one of those rules exists so the coach can stay quiet.
 * The digest breaks all three by design: it repeats the same standing facts every
 * morning because a status board that only reports changes is not a status board.
 * Putting both behaviours behind one service would mean a caller could no longer
 * tell, from the type, whether it was about to burn a band.
 *
 * It reuses {@see PaceEvaluator} on purpose. Which categories count as over or
 * heading over — including the envelope table for yearly budgets — is decided in
 * exactly one place, tested without a database, and shared. If this class
 * re-derived those thresholds the two voices would eventually disagree in front
 * of the user, which is worse than either being wrong alone.
 *
 * What it does NOT reuse is the ledger. There is no `claim()` anywhere below,
 * and that absence is the design.
 */
final class BudgetDigestService
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly PaceEvaluator $evaluator,
        private readonly BudgetDigestComposer $composer,
        private readonly ChannelIdentityResolver $identities,
        private readonly CaptureChannelRegistry $channels,
    ) {}

    /**
     * Composes and sends. Returns true only when a message reached the channel
     * adapter; false for every silent outcome — disabled, nothing over and
     * nothing heading over, or no reachable channel.
     */
    public function send(User $user): bool
    {
        $message = $this->compose($user);

        if ($message === null) {
            return false;
        }

        $identity = $this->identities->preferredIdentityFor($user->id, (array) config('coaching.channels'));

        if ($identity === null) {
            return false;
        }

        $this->channels->for($identity->channel)->reply($identity->external_id, $message);

        return true;
    }

    /**
     * What {@see send()} would say, without sending it. Unlike the coach's
     * `preview()`, this needs no special care: with nothing to claim there is no
     * state for a dry run to burn, which is the whole reason the digest is
     * cheap to inspect.
     */
    public function compose(User $user): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $cursor = MonthCursor::forInstant(CarbonImmutable::now((string) config('app.timezone')));

        $snapshots = $this->categories->expenseBudgetSnapshotsForMonth(
            $user->id,
            $cursor->periodMonth->month,
            $cursor->periodMonth->year,
        );

        $observations = $this->evaluator->evaluate($snapshots, $cursor, $this->thresholds());

        return $this->composer->compose($observations, $cursor);
    }

    /**
     * Both switches have to be on. `coaching.enabled` is the subsystem's kill
     * switch and silencing it must silence every voice it owns, including this
     * one — a rollback that leaves one channel still talking is not a rollback.
     * `coaching.digest_enabled` then turns off the morning message alone, for the
     * likelier case: the owner wants the 20:00 narration and not two messages a
     * day.
     */
    private function enabled(): bool
    {
        return (bool) config('coaching.enabled') && (bool) config('coaching.digest_enabled');
    }

    /**
     * The evaluator's thresholds, with one substitution: the digest lists more
     * categories than the coach speaks about.
     *
     * `max_observations_per_message` caps the coach at three because a narration
     * with a cause per line gets long fast. A digest line is one short row, and
     * truncating it silently would produce the worst possible failure for a
     * status board — a list that looks complete and is not.
     */
    private function thresholds(): PaceThresholds
    {
        return new PaceThresholds(
            minDayForProjection: (int) config('coaching.min_day_for_projection'),
            overrunMargin: (float) config('coaching.overrun_margin'),
            lumpyShare: (float) config('coaching.lumpy_share'),
            maxObservations: (int) config('coaching.digest_max_categories'),
            envelopeConsumedShare: (float) config('coaching.envelope_consumed_share'),
            envelopeMinMonthsRemaining: (int) config('coaching.envelope_min_months_remaining'),
        );
    }
}
