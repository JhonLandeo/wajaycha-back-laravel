<?php

declare(strict_types=1);

namespace App\Actions\PaydayPlan;

use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\DTOs\PaydayPlan\PaydayRunReport;
use App\Models\ChannelIdentity;
use App\Models\PaydayCycle;
use App\Models\PaydayPlan;
use App\Repositories\Contracts\PaydayCycleRepositoryContract;
use App\Repositories\Contracts\PaydayPlanRepositoryContract;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\TelegramChannel;
use App\Services\PaydayPlan\PaydayCalendar;
use App\Services\PaydayPlan\PaydayCycleTransitions;
use App\Services\PaydayPlan\PaydayPlanComposer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily 09:00 run: sends each eligible user the plan message, once per month.
 *
 * Claim-by-INSERT comes first and the send second, so a re-run or a concurrent
 * run can never send a second plan message for a month. A cycle whose message
 * never went out (rejected by Telegram, or claimed and then the worker died) is
 * retried by later runs inside the grace window, owned by a compare-and-swap on
 * `send_attempts`.
 */
final class DeliverPaydayPlanAction
{
    private const SKIPPED = 'skipped';

    private const SENT = 'sent';

    private const FAILED = 'failed';

    public function __construct(
        private readonly PaydayPlanRepositoryContract $plans,
        private readonly PaydayCycleRepositoryContract $cycles,
        private readonly PaydayCalendar $calendar,
        private readonly PaydayCycleTransitions $transitions,
        private readonly PaydayPlanComposer $composer,
        private readonly ChannelIdentityResolver $identities,
        private readonly TelegramChannel $telegram,
    ) {}

    public function execute(CarbonImmutable $now, bool $dryRun = false): PaydayRunReport
    {
        if (! (bool) config('payday.enabled')) {
            return new PaydayRunReport(dryRun: $dryRun, disabled: true);
        }

        // Expiry first: last month's open cycles must be closed before this
        // month's run decides anything.
        $expired = $dryRun ? 0 : $this->cycles->expirePriorMonths($this->calendar->periodMonth($now));

        $considered = $sent = $failed = $retried = $skipped = $errors = 0;

        foreach ($this->plans->activePlans() as $plan) {
            $considered++;

            try {
                [$outcome, $wasRetry] = $this->deliverTo($plan, $now, $dryRun);
            } catch (Throwable $e) {
                // One user's failure must not stop everyone behind them.
                Log::error("❌ Payday: falló el envío del plan {$plan->id}. ".$e::class);
                $errors++;

                continue;
            }

            match ($outcome) {
                self::SENT => $sent++,
                self::FAILED => $failed++,
                default => $skipped++,
            };

            if ($wasRetry && $outcome !== self::SKIPPED) {
                $retried++;
            }
        }

        return new PaydayRunReport($dryRun, false, $considered, $sent, $failed, $retried, $skipped, $expired, $errors);
    }

    /** @return array{0: string, 1: bool} outcome and whether it was a retry */
    private function deliverTo(PaydayPlan $plan, CarbonImmutable $now, bool $dryRun): array
    {
        $identity = $this->identities->preferredIdentityFor($plan->user_id, ['telegram']);

        if ($identity === null) {
            return [self::SKIPPED, false];
        }

        $periodMonth = $this->calendar->periodMonth($now);
        $cycle = $this->cycles->findForMonth($plan->user_id, $periodMonth);

        if ($cycle === null) {
            if (! $this->calendar->isEligible($now, $plan->payday_day)) {
                return [self::SKIPPED, false];
            }

            if ($dryRun) {
                return [self::SENT, false];
            }

            $snapshot = PaydayPlanSnapshot::fromDeclaration($plan->payday_day, (string) $plan->declared_salary, $plan->savings_percent);
            $cycle = $this->cycles->claim($plan->user_id, $periodMonth, $this->calendar->paydayOn($now, $plan->payday_day), $snapshot);

            // Null means another run claimed it first: that run owns the send.
            return $cycle === null ? [self::SKIPPED, false] : [$this->send($cycle, $identity, $now), false];
        }

        $withinGrace = $this->calendar->isWithinGrace($now, $cycle->created_at, (int) config('payday.grace_days'));

        if (! $this->transitions->isRetryable($cycle->status, $cycle->sent_at !== null, $withinGrace)) {
            return [self::SKIPPED, false];
        }

        if ($dryRun) {
            return [self::SENT, true];
        }

        if (! $this->cycles->beginAttempt($cycle)) {
            return [self::SKIPPED, true];
        }

        return [$this->send($cycle, $identity, $now), true];
    }

    private function send(PaydayCycle $cycle, ChannelIdentity $identity, CarbonImmutable $now): string
    {
        $message = $this->composer->plan(PaydayPlanSnapshot::fromArray($cycle->plan_snapshot), $cycle->id);

        if (! $this->telegram->replyWithKeyboard($identity->external_id, $message->text, $message->keyboard)) {
            $this->cycles->markSendFailed($cycle->id);

            return self::FAILED;
        }

        $this->cycles->markSent(
            $cycle->id,
            $now,
            $this->transitions->rearmsReminder($cycle->reminded_at !== null) ? $this->calendar->reminderDueOn($now) : null,
        );

        return self::SENT;
    }
}
