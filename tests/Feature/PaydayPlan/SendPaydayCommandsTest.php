<?php

declare(strict_types=1);

use App\Enums\PaydayCycleStatus as S;
use App\Models\PaydayCycle;
use Tests\Support\PaydayFixtures as F;

beforeEach(function () {
    F::enable();
});

it('sends the plan to an eligible user and reports the counters', function () {
    $this->travelTo(F::at('2026-10-03 09:00'));
    F::userWithPlan(['payday_day' => 3]);

    $this->artisan('app:send-payday-plan')
        ->expectsOutputToContain('1 considerados, 1 enviados, 0 fallidos')
        ->assertSuccessful();

    expect(F::sentTexts())->toHaveCount(1)
        ->and(PaydayCycle::count())->toBe(1);
});

it('previews with --dry-run without claiming a cycle or sending anything', function () {
    $this->travelTo(F::at('2026-10-03 09:00'));
    F::userWithPlan(['payday_day' => 3]);

    $this->artisan('app:send-payday-plan', ['--dry-run' => true])
        ->expectsOutputToContain('(dry-run): 1 considerados, 1 enviados')
        ->assertSuccessful();

    expect(F::sent())->toBe([])
        ->and(PaydayCycle::count())->toBe(0);
});

it('says the feature is off and does nothing when the kill switch is down', function () {
    config()->set('payday.enabled', false);
    $this->travelTo(F::at('2026-10-03 09:00'));
    F::userWithPlan(['payday_day' => 3]);

    $this->artisan('app:send-payday-plan')
        ->expectsOutputToContain('apagado')
        ->assertSuccessful();

    expect(F::sent())->toBe([])
        ->and(PaydayCycle::count())->toBe(0);
});

it('reports a rejected send as failed and leaves the cycle for the retry', function () {
    $this->travelTo(F::at('2026-10-03 09:00'));
    F::userWithPlan(['payday_day' => 3]);
    F::reject();

    $this->artisan('app:send-payday-plan')
        ->expectsOutputToContain('1 considerados, 0 enviados, 1 fallidos')
        ->assertSuccessful();

    expect(PaydayCycle::first()->status)->toBe(S::SendFailed);
});

it('sends the single reminder for a pending cycle and reports it', function () {
    $this->travelTo(F::at('2026-10-04 20:00'));
    $user = F::userWithPlan();
    PaydayCycle::factory()->create([
        'user_id' => $user->id,
        'status' => S::Pending,
        'sent_at' => F::at('2026-10-03 09:00'),
        'send_attempts' => 1,
        'reminder_due_on' => '2026-10-04',
    ]);

    $this->artisan('app:send-payday-reminder')
        ->expectsOutputToContain('1 considerados, 1 enviados')
        ->assertSuccessful();

    expect(F::sentTexts())->toHaveCount(1);

    $this->artisan('app:send-payday-reminder')->assertSuccessful();

    expect(F::sentTexts())->toHaveCount(1);
});

it('previews the reminder with --dry-run and claims nothing', function () {
    $this->travelTo(F::at('2026-10-04 20:00'));
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create([
        'user_id' => $user->id,
        'status' => S::Pending,
        'sent_at' => F::at('2026-10-03 09:00'),
        'reminder_due_on' => '2026-10-04',
    ]);

    $this->artisan('app:send-payday-reminder', ['--dry-run' => true])
        ->expectsOutputToContain('dry-run')
        ->assertSuccessful();

    expect(F::sent())->toBe([])
        ->and($cycle->fresh()->reminded_at)->toBeNull();
});

it('reminds nobody when the feature is off', function () {
    config()->set('payday.enabled', false);
    $this->travelTo(F::at('2026-10-04 20:00'));

    $this->artisan('app:send-payday-reminder')
        ->expectsOutputToContain('apagado')
        ->assertSuccessful();
});

it('exits with failure when a user errored so the Sentry check-in turns red', function () {
    $this->travelTo(F::at('2026-10-03 09:00'));
    F::userWithPlan(['payday_day' => 3]);
    $this->mock(App\Services\Capture\ChannelIdentityResolver::class)
        ->shouldReceive('preferredIdentityFor')
        ->andThrow(new RuntimeException('boom'));

    $this->artisan('app:send-payday-plan')
        ->expectsOutputToContain('1 errores')
        ->assertFailed();
});
