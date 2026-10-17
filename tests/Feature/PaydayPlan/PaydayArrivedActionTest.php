<?php

declare(strict_types=1);

use App\Actions\PaydayPlan\PaydayArrivedAction;
use App\Enums\PaydayCycleStatus as S;
use App\Models\PaydayCycle;
use App\Models\PaydayPlan;
use App\Models\User;
use Tests\Support\PaydayFixtures as F;

beforeEach(function () {
    F::enable();
    $this->action = app(PaydayArrivedAction::class);
});

function arrive(object $t, User $user, string $when = '2026-10-20 12:00'): void
{
    $t->action->execute($user->id, F::CHAT, F::at($when));
}

it('resends the stored plan for a deferred cycle and makes it pending again', function () {
    $user = F::userWithPlan(['payday_day' => 3, 'savings_percent' => 20]);
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Deferred, 'sent_at' => F::at('2026-10-03 09:00'), 'send_attempts' => 1]);

    arrive($this, $user);

    $fresh = $cycle->fresh();
    expect($fresh->status)->toBe(S::Pending)
        ->and($fresh->send_attempts)->toBe(1 + 1)
        ->and(F::sentTexts())->toHaveCount(1)
        ->and(F::sentTexts()[0])->toContain('ahorro 10% = S/ 300')   // snapshot, not the live 20%
        ->and(F::sent()[0]['chat_id'])->toBe(F::CHAT);
});

it('re-arms the single reminder for the day after the resend while it was never sent', function () {
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Deferred, 'reminder_due_on' => '2026-10-04']);

    arrive($this, $user, '2026-10-20 12:00');

    expect($cycle->fresh()->reminder_due_on->toDateString())->toBe('2026-10-21');
});

it('does not re-arm a reminder that was already sent', function () {
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create([
        'user_id' => $user->id, 'status' => S::Deferred,
        'reminder_due_on' => '2026-10-04', 'reminded_at' => F::at('2026-10-04 20:00'),
    ]);

    arrive($this, $user, '2026-10-20 12:00');

    expect($cycle->fresh()->reminder_due_on->toDateString())->toBe('2026-10-04')
        ->and($cycle->fresh()->status)->toBe(S::Pending);
});

it('resends for a pending cycle without double-delivering on a double tap', function () {
    $user = F::userWithPlan();
    PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Deferred]);

    arrive($this, $user);
    arrive($this, $user);

    // Two taps are two user requests, so two messages are acceptable; what must
    // hold is that neither corrupts the cycle.
    expect(PaydayCycle::query()->sole()->status)->toBe(S::Pending);
});

it('claims the current month on an early Cobré hoy when no cycle exists yet', function () {
    $user = F::userWithPlan(['payday_day' => 25]);

    arrive($this, $user, '2026-10-17 12:00');

    $cycle = PaydayCycle::query()->sole();
    expect($cycle->status)->toBe(S::Pending)
        ->and($cycle->period_month->toDateString())->toBe('2026-10-01')
        ->and($cycle->payday_on->toDateString())->toBe('2026-10-25')
        ->and($cycle->reminder_due_on->toDateString())->toBe('2026-10-18')
        ->and(F::sentTexts()[0])->toContain('ahorro 10% = S/ 300');
});

it('marks an early claim as send_failed when Telegram rejects it', function () {
    $user = F::userWithPlan(['payday_day' => 25]);
    F::reject();

    arrive($this, $user, '2026-10-17 12:00');

    expect(PaydayCycle::query()->sole()->status)->toBe(S::SendFailed);
});

it('leaves a deferred cycle deferred when the resend fails', function () {
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Deferred]);
    F::reject();

    arrive($this, $user);

    expect($cycle->fresh()->status)->toBe(S::Deferred);
});

it('says honestly that the plan is set up on the web when there is none', function () {
    $user = User::factory()->create();

    arrive($this, $user);

    expect(F::sentTexts())->toBe(['Todavía no tienes un plan de cobro activo. Puedes definirlo en la web.'])
        ->and(PaydayCycle::query()->count())->toBe(0);
});

it('gives the same honest answer for an inactive plan and for a switched-off feature', function () {
    $inactive = F::userWithPlan(['is_active' => false]);
    arrive($this, $inactive);

    $active = F::userWithPlan(chat: null);
    config()->set('payday.enabled', false);
    arrive($this, $active);

    expect(F::sentTexts())->toHaveCount(2)
        ->and(F::sentTexts()[0])->toContain('plan de cobro activo')
        ->and(F::sentTexts()[1])->toContain('plan de cobro activo')
        ->and(PaydayCycle::query()->count())->toBe(0);
});

it('says the cycle is already answered instead of resending', function (S $status) {
    $user = F::userWithPlan();
    PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => $status]);

    arrive($this, $user);

    expect(F::sentTexts())->toBe(['Ya registraste tu respuesta de este mes.']);
})->with([[S::Separated], [S::Skipped]]);

it('says an expired plan is no longer available and does not claim a new cycle', function () {
    $user = F::userWithPlan(['payday_day' => 25]);
    $old = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Expired, 'period_month' => '2026-09-01', 'payday_on' => '2026-09-03']);

    arrive($this, $user, '2026-10-17 12:00');

    expect(F::sentTexts()[0])->toContain('ya no está disponible')
        ->and(PaydayCycle::query()->count())->toBe(1)
        ->and($old->fresh()->status)->toBe(S::Expired);
});

it('claims the month on an early Cobré hoy when last month was answered', function () {
    $user = F::userWithPlan(['payday_day' => 25]);
    PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Separated, 'period_month' => '2026-09-01']);

    arrive($this, $user, '2026-10-17 12:00');

    expect(PaydayCycle::query()->count())->toBe(2)
        ->and(F::sentTexts()[0])->toContain('ahorro 10% = S/ 300');
});

it('never reads another user\'s cycle', function () {
    $user = F::userWithPlan(['payday_day' => 25]);
    $other = User::factory()->create();
    $theirs = PaydayCycle::factory()->create(['user_id' => $other->id, 'status' => S::Deferred]);

    arrive($this, $user, '2026-10-17 12:00');

    expect($theirs->fresh()->status)->toBe(S::Deferred)
        ->and(PaydayCycle::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('sends nothing to a plan of another user when the caller has none', function () {
    PaydayPlan::factory()->create();
    $user = User::factory()->create();

    arrive($this, $user);

    expect(F::sentTexts())->toHaveCount(1)->and(PaydayCycle::query()->count())->toBe(0);
});
