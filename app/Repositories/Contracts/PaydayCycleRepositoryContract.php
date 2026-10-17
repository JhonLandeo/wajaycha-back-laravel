<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\Enums\PaydayCycleStatus;
use App\Enums\PaydaySkipReason;
use App\Models\PaydayCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Owns `payday_cycles`.
 *
 * Every write that can race is a conditional UPDATE whose affected-row count
 * decides who owns the work — the same idiom as the coaching ledger — so no
 * method here reads first and writes second.
 */
interface PaydayCycleRepositoryContract
{
    /**
     * Claims the month by INSERT, before anything is sent. Returns the new cycle
     * (pending, first attempt counted, snapshot stored) or null when this user
     * already has a cycle for `$periodMonth` — whether from a re-run or from a
     * concurrent run. Safe inside a caller's transaction.
     */
    public function claim(int $userId, CarbonImmutable $periodMonth, CarbonImmutable $paydayOn, PaydayPlanSnapshot $snapshot): ?PaydayCycle;

    public function findForMonth(int $userId, CarbonImmutable $periodMonth): ?PaydayCycle;

    /** The cycle only if it belongs to `$userId`; a foreign id is indistinguishable from a missing one. */
    public function findOwned(int $userId, int $cycleId): ?PaydayCycle;

    public function latestFor(int $userId): ?PaydayCycle;

    /**
     * Records an accepted delivery: the cycle becomes pending with `sent_at` set.
     * `$reminderDueOn` null leaves the reminder date untouched. A no-op for a
     * cycle the user has already answered (or that expired) in the meantime.
     */
    public function markSent(int $cycleId, CarbonImmutable $sentAt, ?CarbonImmutable $reminderDueOn): void;

    /** Records a rejected delivery. A no-op for any cycle that is not pending or already send_failed. */
    public function markSendFailed(int $cycleId): void;

    /**
     * Takes ownership of one more send attempt by compare-and-swap on
     * `send_attempts`: of any number of callers holding the same cycle row, one
     * gets true. Refused for a cycle that is answered or expired.
     */
    public function beginAttempt(PaydayCycle $cycle): bool;

    /**
     * Pending cycles whose single reminder falls due on `$today`.
     *
     * @return Collection<int, PaydayCycle>
     */
    public function dueForReminder(CarbonImmutable $today): Collection;

    /**
     * Owns the one reminder: sets `reminded_at` only for a pending, never-reminded
     * cycle due `$today`. Taken before sending, so a failed send is never retried.
     */
    public function claimReminder(int $cycleId, CarbonImmutable $today): bool;

    /**
     * Turns every open or undelivered cycle of a month before `$currentMonth`
     * into expired. Answered cycles are left alone. Returns the rows changed.
     */
    public function expirePriorMonths(CarbonImmutable $currentMonth): int;

    /** Changes the status only if it is still `$from`; clears any skip reason. */
    public function applyAnswer(int $cycleId, PaydayCycleStatus $from, PaydayCycleStatus $to, CarbonImmutable $at): bool;

    /** Stores the reason, only on a skipped cycle. */
    public function setSkipReason(int $cycleId, PaydaySkipReason $reason, CarbonImmutable $at): bool;
}
