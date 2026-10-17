<?php

declare(strict_types=1);

namespace App\Actions\PaydayPlan;

use App\DTOs\PaydayPlan\PaydayMessage;
use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\Enums\PaydayCycleStatus;
use App\Models\PaydayCycle;
use App\Repositories\Contracts\PaydayCycleRepositoryContract;
use App\Repositories\Contracts\PaydayPlanRepositoryContract;
use App\Services\Capture\TelegramChannel;
use App\Services\PaydayPlan\PaydayCalendar;
use App\Services\PaydayPlan\PaydayCycleTransitions;
use App\Services\PaydayPlan\PaydayPlanComposer;
use Carbon\CarbonImmutable;

/**
 * "Cobré hoy": the user says the salary arrived, from the deferral button or from
 * /menu. Re-sends the plan from the stored snapshot, or claims this month's cycle
 * right now when none exists yet (an early payday).
 *
 * This is the user pulling, so it speaks even when the daily run would not; it
 * still refuses when the feature is off or there is no active plan, and says so
 * honestly instead of staying silent.
 */
final class PaydayArrivedAction
{
    public function __construct(
        private readonly PaydayPlanRepositoryContract $plans,
        private readonly PaydayCycleRepositoryContract $cycles,
        private readonly PaydayCalendar $calendar,
        private readonly PaydayCycleTransitions $transitions,
        private readonly PaydayPlanComposer $composer,
        private readonly TelegramChannel $telegram,
    ) {}

    /** Sends the answer to `$chatId` and returns the message it chose. */
    public function execute(int $userId, string $chatId, CarbonImmutable $now): PaydayMessage
    {
        $message = $this->handle($userId, $chatId, $now);

        if ($message !== null) {
            $this->say($chatId, $message);
        }

        return $message ?? new PaydayMessage('');
    }

    /** Null when the plan message itself was the answer and has already been sent. */
    private function handle(int $userId, string $chatId, CarbonImmutable $now): ?PaydayMessage
    {
        $plan = $this->plans->findForUser($userId);

        if (! (bool) config('payday.enabled') || $plan === null || ! $plan->is_active) {
            return new PaydayMessage($this->composer->noPlan());
        }

        $periodMonth = $this->calendar->periodMonth($now);
        $cycle = $this->cycles->findForMonth($userId, $periodMonth);

        if ($cycle === null) {
            if ($this->cycles->latestFor($userId)?->status === PaydayCycleStatus::Expired) {
                return new PaydayMessage($this->composer->expired());
            }

            $snapshot = PaydayPlanSnapshot::fromDeclaration($plan->payday_day, (string) $plan->declared_salary, $plan->savings_percent);
            $cycle = $this->cycles->claim($userId, $periodMonth, $this->calendar->paydayOn($now, $plan->payday_day), $snapshot);

            if ($cycle === null) {
                return new PaydayMessage($this->composer->stale());
            }

            $this->deliver($cycle, $chatId, $now, failureMarksCycle: true);

            return null;
        }

        if ($this->transitions->afterPaid($cycle->status) === null) {
            return new PaydayMessage(
                $cycle->status === PaydayCycleStatus::Expired ? $this->composer->expired() : $this->composer->alreadyAnswered(),
            );
        }

        if (! $this->cycles->beginAttempt($cycle)) {
            return new PaydayMessage($this->composer->stale());
        }

        $this->deliver($cycle, $chatId, $now, failureMarksCycle: false);

        return null;
    }

    /**
     * @param  bool  $failureMarksCycle  a fresh claim that could not be delivered is
     *                                   handed to the daily retry; an existing cycle
     *                                   keeps the status it had.
     */
    private function deliver(PaydayCycle $cycle, string $chatId, CarbonImmutable $now, bool $failureMarksCycle): void
    {
        $message = $this->composer->plan(PaydayPlanSnapshot::fromArray($cycle->plan_snapshot), $cycle->id);

        if (! $this->telegram->replyWithKeyboard($chatId, $message->text, $message->keyboard)) {
            if ($failureMarksCycle) {
                $this->cycles->markSendFailed($cycle->id);
            }

            return;
        }

        $this->cycles->markSent(
            $cycle->id,
            $now,
            $this->transitions->rearmsReminder($cycle->reminded_at !== null) ? $this->calendar->reminderDueOn($now) : null,
        );
    }

    private function say(string $chatId, PaydayMessage $message): void
    {
        if ($message->keyboard === []) {
            $this->telegram->reply($chatId, $message->text);

            return;
        }

        $this->telegram->replyWithKeyboard($chatId, $message->text, $message->keyboard);
    }
}
