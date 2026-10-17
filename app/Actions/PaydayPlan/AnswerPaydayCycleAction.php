<?php

declare(strict_types=1);

namespace App\Actions\PaydayPlan;

use App\DTOs\PaydayPlan\PaydayCallback;
use App\DTOs\PaydayPlan\PaydayMessage;
use App\Enums\PaydayCycleStatus;
use App\Models\PaydayCycle;
use App\Repositories\Contracts\PaydayCycleRepositoryContract;
use App\Services\PaydayPlan\PaydayCalendar;
use App\Services\PaydayPlan\PaydayCycleTransitions;
use App\Services\PaydayPlan\PaydayPlanComposer;
use Carbon\CarbonImmutable;

/**
 * Applies one button press (`p:{id}:y|n|w` or `p:{id}:r:{code}`) and returns
 * what to say back.
 *
 * `$userId` is the user resolved from the chat, never from the callback. The
 * cycle must belong to that user and be their latest; anything else gets the
 * same polite "no aplica", so a foreign id reveals nothing about whether it
 * exists (PA-1, PA-2).
 */
final class AnswerPaydayCycleAction
{
    public function __construct(
        private readonly PaydayCycleRepositoryContract $cycles,
        private readonly PaydayCalendar $calendar,
        private readonly PaydayCycleTransitions $transitions,
        private readonly PaydayPlanComposer $composer,
    ) {}

    public function execute(int $userId, PaydayCallback $callback, CarbonImmutable $now): PaydayMessage
    {
        $cycle = $callback->cycleId === null ? null : $this->cycles->findOwned($userId, $callback->cycleId);

        if ($cycle === null || ! $this->isLatest($userId, $cycle)) {
            return $this->stale();
        }

        $monthIsCurrent = $this->calendar->isCurrentMonth($cycle->period_month, $now);

        if ($callback->reason !== null) {
            return $this->storeReason($cycle, $callback, $monthIsCurrent, $now);
        }

        if ($callback->answer === null) {
            return $this->stale();
        }

        $target = $this->transitions->afterAnswer($cycle->status, $callback->answer, $monthIsCurrent);

        if ($target === null) {
            return $this->stale();
        }

        if ($target === $cycle->status) {
            return $this->repeated($cycle);
        }

        if (! $this->cycles->applyAnswer($cycle->id, $cycle->status, $target, $now)) {
            // Lost a race with another tap on the same cycle.
            return new PaydayMessage($this->composer->alreadyAnswered());
        }

        return $this->reply($cycle, $target);
    }

    private function storeReason(PaydayCycle $cycle, PaydayCallback $callback, bool $monthIsCurrent, CarbonImmutable $now): PaydayMessage
    {
        if (! $this->transitions->acceptsReason($cycle->status, $monthIsCurrent)
            || ! $this->cycles->setSkipReason($cycle->id, $callback->reason, $now)) {
            return $this->stale();
        }

        return new PaydayMessage($this->composer->skipped($callback->reason));
    }

    /** The same status tapped again: no write, a polite reply. */
    private function repeated(PaydayCycle $cycle): PaydayMessage
    {
        return match (true) {
            $cycle->status === PaydayCycleStatus::Deferred => $this->composer->deferred(),
            $cycle->status === PaydayCycleStatus::Skipped && $cycle->skip_reason === null => $this->composer->skipReasonPrompt($cycle->id),
            default => new PaydayMessage($this->composer->alreadyAnswered()),
        };
    }

    private function reply(PaydayCycle $cycle, PaydayCycleStatus $target): PaydayMessage
    {
        return match ($target) {
            PaydayCycleStatus::Separated => new PaydayMessage($this->composer->separated()),
            PaydayCycleStatus::Skipped => $this->composer->skipReasonPrompt($cycle->id),
            default => $this->composer->deferred(),
        };
    }

    private function isLatest(int $userId, PaydayCycle $cycle): bool
    {
        return $this->cycles->latestFor($userId)?->id === $cycle->id;
    }

    private function stale(): PaydayMessage
    {
        return new PaydayMessage($this->composer->stale());
    }
}
