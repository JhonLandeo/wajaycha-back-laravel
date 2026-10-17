<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\Enums\PaydayCycleStatus;
use App\Enums\PaydaySkipReason;
use App\Models\PaydayCycle;
use App\Repositories\Contracts\PaydayCycleRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PaydayCycleRepository implements PaydayCycleRepositoryContract
{
    /** Statuses in which a plan message may still be (re)delivered. */
    private const DELIVERABLE = [
        PaydayCycleStatus::Pending->value,
        PaydayCycleStatus::Deferred->value,
        PaydayCycleStatus::SendFailed->value,
    ];

    public function claim(int $userId, CarbonImmutable $periodMonth, CarbonImmutable $paydayOn, PaydayPlanSnapshot $snapshot): ?PaydayCycle
    {
        try {
            // Own savepoint: in PostgreSQL a constraint violation aborts the
            // enclosing transaction, so catching it without one would leave any
            // caller's transaction unusable.
            return DB::transaction(fn (): PaydayCycle => PaydayCycle::create([
                'user_id' => $userId,
                'period_month' => $periodMonth->toDateString(),
                'payday_on' => $paydayOn->toDateString(),
                'plan_snapshot' => $snapshot->toArray(),
                'status' => PaydayCycleStatus::Pending,
                'send_attempts' => 1,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function findForMonth(int $userId, CarbonImmutable $periodMonth): ?PaydayCycle
    {
        /** @var PaydayCycle|null $cycle */
        $cycle = PaydayCycle::query()
            ->where('user_id', $userId)
            ->where('period_month', $periodMonth->toDateString())
            ->first();

        return $cycle;
    }

    public function findOwned(int $userId, int $cycleId): ?PaydayCycle
    {
        /** @var PaydayCycle|null $cycle */
        $cycle = PaydayCycle::query()
            ->where('id', $cycleId)
            ->where('user_id', $userId)
            ->first();

        return $cycle;
    }

    public function latestFor(int $userId): ?PaydayCycle
    {
        /** @var PaydayCycle|null $cycle */
        $cycle = PaydayCycle::query()
            ->where('user_id', $userId)
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->first();

        return $cycle;
    }

    public function markSent(int $cycleId, CarbonImmutable $sentAt, ?CarbonImmutable $reminderDueOn): void
    {
        $values = [
            'status' => PaydayCycleStatus::Pending->value,
            'sent_at' => $this->local($sentAt),
        ];

        if ($reminderDueOn !== null) {
            $values['reminder_due_on'] = $reminderDueOn->toDateString();
        }

        PaydayCycle::query()
            ->where('id', $cycleId)
            ->whereIn('status', self::DELIVERABLE)
            ->update($values);
    }

    public function markSendFailed(int $cycleId): void
    {
        PaydayCycle::query()
            ->where('id', $cycleId)
            ->whereIn('status', [PaydayCycleStatus::Pending->value, PaydayCycleStatus::SendFailed->value])
            ->update(['status' => PaydayCycleStatus::SendFailed->value]);
    }

    public function beginAttempt(PaydayCycle $cycle): bool
    {
        return PaydayCycle::query()
            ->where('id', $cycle->id)
            ->where('send_attempts', $cycle->send_attempts)
            ->whereIn('status', self::DELIVERABLE)
            ->update(['send_attempts' => $cycle->send_attempts + 1]) === 1;
    }

    public function dueForReminder(CarbonImmutable $today): Collection
    {
        // Same predicate as the partial index: status = 'pending'.
        return PaydayCycle::query()
            ->where('status', PaydayCycleStatus::Pending->value)
            ->whereNull('reminded_at')
            ->where('reminder_due_on', $today->toDateString())
            ->orderBy('id')
            ->get();
    }

    public function claimReminder(int $cycleId, CarbonImmutable $today): bool
    {
        return PaydayCycle::query()
            ->where('id', $cycleId)
            ->where('status', PaydayCycleStatus::Pending->value)
            ->whereNull('reminded_at')
            ->where('reminder_due_on', $today->toDateString())
            ->update(['reminded_at' => $this->local(CarbonImmutable::now())]) === 1;
    }

    public function expirePriorMonths(CarbonImmutable $currentMonth): int
    {
        return PaydayCycle::query()
            ->where('period_month', '<', $currentMonth->toDateString())
            ->whereIn('status', self::DELIVERABLE)
            ->update(['status' => PaydayCycleStatus::Expired->value]);
    }

    public function applyAnswer(int $cycleId, PaydayCycleStatus $from, PaydayCycleStatus $to, CarbonImmutable $at): bool
    {
        return PaydayCycle::query()
            ->where('id', $cycleId)
            ->where('status', $from->value)
            ->update([
                'status' => $to->value,
                'skip_reason' => null,
                'answered_at' => $this->local($at),
            ]) === 1;
    }

    public function setSkipReason(int $cycleId, PaydaySkipReason $reason, CarbonImmutable $at): bool
    {
        return PaydayCycle::query()
            ->where('id', $cycleId)
            ->where('status', PaydayCycleStatus::Skipped->value)
            ->update([
                'skip_reason' => $reason->value,
                'answered_at' => $this->local($at),
            ]) === 1;
    }

    /**
     * Timestamps are written in the application's own timezone, which is the one
     * the connection reads them back in; an instant held in UTC would otherwise
     * be formatted without its offset and stored five hours off.
     */
    private function local(CarbonImmutable $at): CarbonImmutable
    {
        return $at->setTimezone((string) config('app.timezone'));
    }
}
