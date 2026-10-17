<?php

declare(strict_types=1);

namespace App\Actions\PaydayPlan;

use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\DTOs\PaydayPlan\PaydayRunReport;
use App\Models\PaydayCycle;
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
 * The 20:00 run: at most one reminder per cycle, for a cycle still pending.
 *
 * The reminder is claimed (`reminded_at`) BEFORE it is sent, so a failed send is
 * never retried and nothing can produce a second one. Which day it is due on is
 * the latest delivery's concern: a "Cobré hoy" resend re-arms it while it has not
 * been sent. A deferred cycle is never reminded.
 */
final class SendPaydayReminderAction
{
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

        $today = $this->calendar->today($now);
        $considered = $sent = $failed = $skipped = $errors = 0;

        foreach ($this->cycles->dueForReminder($today) as $cycle) {
            $considered++;

            try {
                $outcome = $this->remind($cycle, $today, $dryRun);
            } catch (Throwable $e) {
                Log::error("❌ Payday: falló el recordatorio del ciclo {$cycle->id}. ".$e::class);
                $errors++;

                continue;
            }

            match ($outcome) {
                'sent' => $sent++,
                'failed' => $failed++,
                default => $skipped++,
            };
        }

        return new PaydayRunReport($dryRun, false, $considered, $sent, $failed, 0, $skipped, 0, $errors);
    }

    private function remind(PaydayCycle $cycle, CarbonImmutable $today, bool $dryRun): string
    {
        $plan = $this->plans->findForUser($cycle->user_id);

        if ($plan === null || ! $plan->is_active) {
            return 'skipped';
        }

        // Checked before the claim: a user with no chat must not lose their one reminder.
        $identity = $this->identities->preferredIdentityFor($cycle->user_id, ['telegram']);

        if ($identity === null || ! $this->transitions->wantsReminder($cycle->status, $cycle->reminded_at !== null)) {
            return 'skipped';
        }

        if ($dryRun) {
            return 'sent';
        }

        if (! $this->cycles->claimReminder($cycle->id, $today)) {
            return 'skipped';
        }

        $message = $this->composer->reminder(PaydayPlanSnapshot::fromArray($cycle->plan_snapshot), $cycle->id);

        return $this->telegram->replyWithKeyboard($identity->external_id, $message->text, $message->keyboard) ? 'sent' : 'failed';
    }
}
