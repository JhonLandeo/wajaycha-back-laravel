<?php

declare(strict_types=1);

namespace App\Services\PaydayPlan;

use App\Enums\PaydayAnswer;
use App\Enums\PaydayCycleStatus as Status;

/**
 * The state machine of a payday cycle. Pure: statuses in, statuses out.
 *
 * A null result always means "not allowed"; a result equal to the current status
 * means "already there" (an idempotent repeat tap). The caller owns the writes.
 */
final class PaydayCycleTransitions
{
    /**
     * @param  bool  $monthIsCurrent  an answer to a cycle of a finished Lima month is never accepted
     */
    public function afterAnswer(Status $current, PaydayAnswer $answer, bool $monthIsCurrent): ?Status
    {
        if (! $monthIsCurrent) {
            return null;
        }

        return match ($answer) {
            PaydayAnswer::Separated => match ($current) {
                Status::Pending, Status::Deferred, Status::Skipped, Status::Separated => Status::Separated,
                default => null,
            },
            PaydayAnswer::Skip => match ($current) {
                Status::Pending, Status::Deferred, Status::Separated, Status::Skipped => Status::Skipped,
                default => null,
            },
            PaydayAnswer::Wait => match ($current) {
                Status::Pending, Status::Deferred => Status::Deferred,
                default => null,
            },
        };
    }

    public function acceptsReason(Status $current, bool $monthIsCurrent): bool
    {
        return $monthIsCurrent && $current === Status::Skipped;
    }

    /** "Cobré hoy": the status the cycle takes once the plan is re-sent, or null when it must not be. */
    public function afterPaid(Status $current): ?Status
    {
        return match ($current) {
            Status::Pending, Status::Deferred, Status::SendFailed => Status::Pending,
            default => null,
        };
    }

    public function afterMonthEnd(Status $current): ?Status
    {
        return match ($current) {
            Status::Pending, Status::Deferred, Status::SendFailed => Status::Expired,
            default => null,
        };
    }

    /**
     * The daily run may re-send when the plan message never went out: a failed
     * send, or a claim whose worker died before sending (pending, never sent).
     */
    public function isRetryable(Status $current, bool $hasBeenSent, bool $withinGrace): bool
    {
        if (! $withinGrace) {
            return false;
        }

        return $current === Status::SendFailed || ($current === Status::Pending && ! $hasBeenSent);
    }

    /** A delivery re-arms the single reminder only if it has not been sent yet. */
    public function rearmsReminder(bool $alreadyReminded): bool
    {
        return ! $alreadyReminded;
    }

    public function wantsReminder(Status $current, bool $alreadyReminded): bool
    {
        return $current === Status::Pending && ! $alreadyReminded;
    }
}
