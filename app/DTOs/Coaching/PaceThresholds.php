<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

/**
 * The tunable knobs of the pace rules, read from config('coaching.*') by the caller.
 *
 * Kept as plain values so PaceEvaluator never reaches into config() itself
 * (design.md D1): these thresholds are product judgement, retuned against real
 * messages, and must stay a config edit plus a unit test — never a migration.
 */
final class PaceThresholds
{
    /**
     * @param  float  $envelopeConsumedShare  share of a yearly envelope that has
     *                                        to be gone before the coach says so.
     *                                        Never a projection: an envelope is
     *                                        consumed, not paced, so this reports
     *                                        depletion that already happened.
     * @param  int  $envelopeMinMonthsRemaining  months that must still be left in
     *                                           the year for depletion to be worth
     *                                           reporting. Telling someone in
     *                                           December that they used 85% of a
     *                                           yearly budget is a fact with
     *                                           nothing behind it.
     */
    public function __construct(
        public readonly int $minDayForProjection,
        public readonly float $overrunMargin,
        public readonly float $lumpyShare,
        public readonly int $maxObservations,
        public readonly float $envelopeConsumedShare = 0.80,
        public readonly int $envelopeMinMonthsRemaining = 2,
    ) {}
}
