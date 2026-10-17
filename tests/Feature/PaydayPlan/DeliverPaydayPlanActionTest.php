<?php

declare(strict_types=1);

use App\Actions\PaydayPlan\DeliverPaydayPlanAction;
use App\Enums\PaydayCycleStatus as S;
use App\Models\PaydayCycle;
use App\Models\PaydayPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaydayFixtures as F;

beforeEach(function () {
    F::enable();
    $this->action = app(DeliverPaydayPlanAction::class);
});

/**
 * Runs the action with the clock frozen at `$when`: the cycle's `created_at`,
 * which the grace window is measured from, comes from the database clock and has
 * to agree with the instant the action is told it is.
 */
function deliverAt(object $t, string $when, bool $dryRun = false): App\DTOs\PaydayPlan\PaydayRunReport
{
    $t->travelTo(F::at($when));

    return $t->action->execute(CarbonImmutable::now(), $dryRun);
}

it('claims the cycle and sends the plan on payday', function () {
    $user = F::userWithPlan(['payday_day' => 3, 'declared_salary' => '3000.00', 'savings_percent' => 10]);

    $report = deliverAt($this, '2026-10-03 09:00');

    $cycle = PaydayCycle::query()->where('user_id', $user->id)->sole();
    expect($report->sent)->toBe(1)
        ->and($cycle->status)->toBe(S::Pending)
        ->and($cycle->period_month->toDateString())->toBe('2026-10-01')
        ->and($cycle->payday_on->toDateString())->toBe('2026-10-03')
        ->and($cycle->send_attempts)->toBe(1)
        ->and($cycle->sent_at)->not->toBeNull()
        ->and($cycle->reminder_due_on->toDateString())->toBe('2026-10-04')
        ->and($cycle->plan_snapshot['savings_amount'])->toBe('300.00');

    $sent = F::sent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['chat_id'])->toBe(F::CHAT)
        ->and($sent[0]['text'])->toContain('ahorro 10% = S/ 300')
        ->and(array_column(array_merge(...$sent[0]['reply_markup']['inline_keyboard']), 'callback_data'))
        ->toBe(["p:{$cycle->id}:y", "p:{$cycle->id}:n", "p:{$cycle->id}:w"]);
});

it('sends nothing before payday and creates no cycle', function () {
    F::userWithPlan(['payday_day' => 3]);

    $report = deliverAt($this, '2026-10-02 09:00');

    expect($report->sent)->toBe(0)
        ->and(PaydayCycle::query()->count())->toBe(0)
        ->and(F::sent())->toBe([]);
});

it('catches up on the days after payday', function () {
    F::userWithPlan(['payday_day' => 3]);

    deliverAt($this, '2026-10-04 09:00');

    expect(PaydayCycle::query()->count())->toBe(1)->and(F::sent())->toHaveCount(1);
});

it('sends in February for day 28', function () {
    F::userWithPlan(['payday_day' => 28]);

    deliverAt($this, '2027-02-28 09:00');

    expect(PaydayCycle::query()->sole()->period_month->toDateString())->toBe('2027-02-01');
});

it('uses the Lima month even when the instant is already the next month in UTC', function () {
    F::userWithPlan(['payday_day' => 1]);

    // 2026-11-01 02:00 UTC is 31 October 21:00 in Lima: the October cycle, if any.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00:00', 'UTC'));
    $report = $this->action->execute(CarbonImmutable::now('UTC'));

    expect($report->sent)->toBe(1)
        ->and(PaydayCycle::query()->sole()->period_month->toDateString())->toBe('2026-10-01');
});

it('does not send a second plan message on a re-run', function () {
    F::userWithPlan(['payday_day' => 3]);

    deliverAt($this, '2026-10-03 09:00');
    $second = deliverAt($this, '2026-10-03 09:30');

    expect($second->sent)->toBe(0)
        ->and(PaydayCycle::query()->count())->toBe(1)
        ->and(F::sent())->toHaveCount(1);
});

it('claims and sends nothing while the kill switch is off', function () {
    F::userWithPlan(['payday_day' => 3]);
    config()->set('payday.enabled', false);

    $report = deliverAt($this, '2026-10-03 09:00');

    expect($report->disabled)->toBeTrue()
        ->and(PaydayCycle::query()->count())->toBe(0)
        ->and(F::sent())->toBe([]);
});

it('never claims or sends an inactive plan', function () {
    F::userWithPlan(['payday_day' => 3, 'is_active' => false]);

    deliverAt($this, '2026-10-03 09:00');

    expect(PaydayCycle::query()->count())->toBe(0)->and(F::sent())->toBe([]);
});

it('skips a user without a linked Telegram chat and creates no cycle for them', function () {
    F::userWithPlan(['payday_day' => 3], chat: null);
    $whatsappOnly = User::factory()->create();
    PaydayPlan::factory()->create(['user_id' => $whatsappOnly->id, 'payday_day' => 3]);
    F::link($whatsappOnly, '51999888777', 'whatsapp');

    $report = deliverAt($this, '2026-10-03 09:00');

    expect($report->sent)->toBe(0)
        ->and(PaydayCycle::query()->count())->toBe(0)
        ->and(F::sent())->toBe([]);
});

it('delivers once a skipped user later links their chat', function () {
    $user = F::userWithPlan(['payday_day' => 3], chat: null);
    deliverAt($this, '2026-10-03 09:00');

    F::link($user);
    deliverAt($this, '2026-10-04 09:00');

    expect(F::sent())->toHaveCount(1);
});

it('records a rejected send as send_failed', function () {
    F::userWithPlan(['payday_day' => 3]);
    F::reject();

    $report = deliverAt($this, '2026-10-03 09:00');

    $cycle = PaydayCycle::query()->sole();
    expect($report->failed)->toBe(1)
        ->and($cycle->status)->toBe(S::SendFailed)
        ->and($cycle->sent_at)->toBeNull();
});

it('retries a failed send on the next run and delivers it once', function () {
    F::userWithPlan(['payday_day' => 3]);
    F::reject();
    deliverAt($this, '2026-10-03 09:00');

    F::accept();
    $report = deliverAt($this, '2026-10-04 09:00');
    $again = deliverAt($this, '2026-10-05 09:00');

    $cycle = PaydayCycle::query()->sole();
    expect($report->sent)->toBe(1)->and($report->retried)->toBe(1)
        ->and($again->sent)->toBe(0)
        ->and($cycle->status)->toBe(S::Pending)
        ->and($cycle->send_attempts)->toBe(2)
        ->and($cycle->reminder_due_on->toDateString())->toBe('2026-10-05')
        ->and(F::sent())->toHaveCount(1);
});

it('still retries on the last day of the grace window', function () {
    F::userWithPlan(['payday_day' => 3]);
    F::reject();
    deliverAt($this, '2026-10-03 09:00');

    F::accept();
    deliverAt($this, '2026-10-06 09:00');

    expect(PaydayCycle::query()->sole()->status)->toBe(S::Pending);
});

it('stops retrying after the grace window and sends nothing more', function () {
    F::userWithPlan(['payday_day' => 3]);
    F::reject();
    deliverAt($this, '2026-10-03 09:00');

    F::accept();
    $report = deliverAt($this, '2026-10-07 09:00');

    expect($report->sent)->toBe(0)
        ->and(PaydayCycle::query()->sole()->status)->toBe(S::SendFailed)
        ->and(F::sent())->toBe([]);
});

it('retries a cycle that was claimed but never sent, inside the grace window', function () {
    $user = F::userWithPlan(['payday_day' => 3]);
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Pending, 'sent_at' => null, 'send_attempts' => 1, 'created_at' => F::at('2026-10-03 09:00')]);

    $report = deliverAt($this, '2026-10-04 09:00');

    expect($report->sent)->toBe(1)
        ->and($cycle->fresh()->sent_at)->not->toBeNull()
        ->and($cycle->fresh()->send_attempts)->toBe(2)
        ->and(F::sent())->toHaveCount(1);
});

it('does not retry a claimed-but-never-sent cycle after the grace window', function () {
    $user = F::userWithPlan(['payday_day' => 3]);
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Pending, 'sent_at' => null, 'created_at' => F::at('2026-10-03 09:00')]);

    $report = deliverAt($this, '2026-10-07 09:00');

    expect($report->sent)->toBe(0)
        ->and($cycle->fresh()->sent_at)->toBeNull()
        ->and(F::sent())->toBe([]);
});

it('does not retry a pending cycle that was delivered', function () {
    $user = F::userWithPlan(['payday_day' => 3]);
    PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Pending, 'sent_at' => F::at('2026-10-03 09:00')]);

    $report = deliverAt($this, '2026-10-04 09:00');

    expect($report->sent)->toBe(0)->and(F::sent())->toBe([]);
});

it('never messages a deferred, answered or expired cycle', function (S $status) {
    $user = F::userWithPlan(['payday_day' => 3]);
    PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => $status, 'sent_at' => F::at('2026-10-03 09:00')]);

    deliverAt($this, '2026-10-20 09:00');

    expect(F::sent())->toBe([])->and(PaydayCycle::query()->sole()->status)->toBe($status);
})->with([[S::Deferred], [S::Separated], [S::Skipped]]);

it('resends from the stored snapshot, not the edited plan', function () {
    $user = F::userWithPlan(['payday_day' => 3, 'declared_salary' => '3000.00', 'savings_percent' => 10]);
    F::reject();
    deliverAt($this, '2026-10-03 09:00');

    PaydayPlan::query()->where('user_id', $user->id)->update(['savings_percent' => 20]);
    F::accept();
    deliverAt($this, '2026-10-04 09:00');

    expect(F::sentTexts()[0])->toContain('ahorro 10% = S/ 300');

    // The next month picks the edited plan up.
    deliverAt($this, '2026-11-03 09:00');
    expect(F::sentTexts()[1])->toContain('ahorro 20% = S/ 600');
});

it('expires last month\'s open cycles before looking at this month', function () {
    $user = F::userWithPlan(['payday_day' => 3]);
    $old = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Deferred, 'period_month' => '2026-09-01', 'payday_on' => '2026-09-03']);
    $answered = PaydayCycle::factory()->create(['user_id' => User::factory()->create()->id, 'status' => S::Separated, 'period_month' => '2026-09-01']);

    $report = deliverAt($this, '2026-10-03 09:00');

    expect($old->fresh()->status)->toBe(S::Expired)
        ->and($answered->fresh()->status)->toBe(S::Separated)
        ->and($report->expired)->toBe(1)
        ->and($report->sent)->toBe(1);
});

it('does nothing at all on a dry run but reports what it would send', function () {
    F::userWithPlan(['payday_day' => 3]);
    $stale = PaydayCycle::factory()->create(['user_id' => User::factory()->create()->id, 'status' => S::Deferred, 'period_month' => '2026-09-01']);

    $report = deliverAt($this, '2026-10-03 09:00', dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->sent)->toBe(1)
        ->and(PaydayCycle::query()->count())->toBe(1)
        ->and($stale->fresh()->status)->toBe(S::Deferred)
        ->and(F::sent())->toBe([]);
});

it('keeps going when one user blows up', function () {
    $first = F::userWithPlan(['payday_day' => 3], chat: '111');
    F::userWithPlan(['payday_day' => 3], chat: '222');
    F::fakeWith(function ($request) {
        if ($request['chat_id'] === '111') {
            throw new RuntimeException('boom');
        }

        return Http::response(['ok' => true]);
    });

    $report = deliverAt($this, '2026-10-03 09:00');

    expect($report->errors)->toBe(1)
        ->and($report->sent)->toBe(1)
        ->and(PaydayCycle::query()->where('user_id', $first->id)->sole()->sent_at)->toBeNull();
});
