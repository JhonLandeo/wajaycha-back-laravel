<?php

declare(strict_types=1);

use App\Actions\PaydayPlan\SendPaydayReminderAction;
use App\Enums\PaydayCycleStatus as S;
use App\Models\PaydayCycle;
use App\Models\User;
use Tests\Support\PaydayFixtures as F;

beforeEach(function () {
    F::enable();
    $this->action = app(SendPaydayReminderAction::class);
});

function dueCycle(array $overrides = [], array $plan = []): PaydayCycle
{
    $user = F::userWithPlan($plan, chat: (string) fake()->unique()->numerify('9########'));

    return PaydayCycle::factory()->create([
        'user_id' => $user->id,
        'status' => S::Pending,
        'sent_at' => F::at('2026-10-03 09:00'),
        'reminder_due_on' => '2026-10-04',
        ...$overrides,
    ]);
}

it('sends one reminder for a pending cycle the day after delivery', function () {
    $cycle = dueCycle();

    $report = $this->action->execute(F::at('2026-10-04 20:00'));

    expect($report->sent)->toBe(1)
        ->and($cycle->fresh()->reminded_at)->not->toBeNull()
        ->and(F::sentTexts())->toHaveCount(1)
        ->and(F::sentTexts()[0])->toContain('ahorro 10% = S/ 300')
        ->and(array_column(array_merge(...F::sent()[0]['reply_markup']['inline_keyboard']), 'callback_data'))
        ->toBe(["p:{$cycle->id}:y", "p:{$cycle->id}:n", "p:{$cycle->id}:w"]);
});

it('never sends a second reminder, however often it runs', function () {
    dueCycle();

    $this->action->execute(F::at('2026-10-04 20:00'));
    $again = $this->action->execute(F::at('2026-10-04 20:05'));
    $nextDay = $this->action->execute(F::at('2026-10-05 20:00'));

    expect($again->sent)->toBe(0)->and($nextDay->sent)->toBe(0)->and(F::sent())->toHaveCount(1);
});

it('sends nothing for a cycle that is not pending', function (S $status) {
    dueCycle(['status' => $status]);

    $report = $this->action->execute(F::at('2026-10-04 20:00'));

    expect($report->sent)->toBe(0)->and(F::sent())->toBe([]);
})->with([[S::Deferred], [S::Separated], [S::Skipped], [S::Expired], [S::SendFailed]]);

it('does not send before the due day', function () {
    dueCycle(['reminder_due_on' => '2026-10-05']);

    $report = $this->action->execute(F::at('2026-10-04 20:00'));

    expect($report->sent)->toBe(0);
});

it('never retries a reminder whose send failed', function () {
    $cycle = dueCycle();
    F::reject();

    $first = $this->action->execute(F::at('2026-10-04 20:00'));
    F::accept();
    $second = $this->action->execute(F::at('2026-10-04 20:30'));

    expect($first->failed)->toBe(1)
        ->and($second->sent)->toBe(0)
        ->and($cycle->fresh()->reminded_at)->not->toBeNull()
        ->and(F::sent())->toBe([]);
});

it('uses the latest delivery: a Cobré hoy resend moves the reminder to the next day', function () {
    $cycle = dueCycle(['reminder_due_on' => '2026-10-21']);

    $early = $this->action->execute(F::at('2026-10-04 20:00'));
    $onTime = $this->action->execute(F::at('2026-10-21 20:00'));

    expect($early->sent)->toBe(0)->and($onTime->sent)->toBe(1)
        ->and($cycle->fresh()->reminded_at)->not->toBeNull();
});

it('does nothing while the kill switch is off', function () {
    $cycle = dueCycle();
    config()->set('payday.enabled', false);

    $report = $this->action->execute(F::at('2026-10-04 20:00'));

    expect($report->disabled)->toBeTrue()
        ->and($cycle->fresh()->reminded_at)->toBeNull()
        ->and(F::sent())->toBe([]);
});

it('does not remind a user whose plan was deactivated', function () {
    $cycle = dueCycle(plan: ['is_active' => false]);

    $report = $this->action->execute(F::at('2026-10-04 20:00'));

    expect($report->sent)->toBe(0)->and($cycle->fresh()->reminded_at)->toBeNull();
});

it('does not burn the reminder for a user without a Telegram chat', function () {
    $user = User::factory()->create();
    App\Models\PaydayPlan::factory()->create(['user_id' => $user->id]);
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Pending, 'reminder_due_on' => '2026-10-04']);

    $report = $this->action->execute(F::at('2026-10-04 20:00'));

    expect($report->sent)->toBe(0)->and($cycle->fresh()->reminded_at)->toBeNull();
});

it('reports without writing or sending on a dry run', function () {
    $cycle = dueCycle();

    $report = $this->action->execute(F::at('2026-10-04 20:00'), dryRun: true);

    expect($report->dryRun)->toBeTrue()->and($report->sent)->toBe(1)
        ->and($cycle->fresh()->reminded_at)->toBeNull()
        ->and(F::sent())->toBe([]);
});

it('keeps going when one reminder blows up', function () {
    dueCycle();
    dueCycle();
    F::fakeWith(fn () => throw new RuntimeException('boom'));

    $report = $this->action->execute(F::at('2026-10-04 20:00'));

    expect($report->errors)->toBe(2);
});
