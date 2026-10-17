<?php

declare(strict_types=1);

use App\DTOs\PaydayPlan\PaydayCallback;
use App\Enums\BotMenuAction;
use App\Enums\PaydayCycleStatus as S;
use App\Jobs\ProcessTelegramMenu;
use App\Models\PaydayCycle;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\TelegramChannel;
use App\Services\Coaching\BudgetDigestService;
use App\Services\Coaching\DailyAllowanceService;
use App\Services\Coaching\MonthOverMonthService;
use App\Services\Coaching\SpendingRhythmService;
use Tests\Support\PaydayFixtures as F;

it('offers Cobré hoy with the same value the callback parser reads', function () {
    expect(BotMenuAction::PAYDAY_ARRIVED->value)->toBe(PaydayCallback::PAID)
        ->and(BotMenuAction::PAYDAY_ARRIVED->label())->toBe('Cobré hoy')
        ->and(strlen(BotMenuAction::PAYDAY_ARRIVED->value))->toBeLessThanOrEqual(64)
        ->and(BotMenuAction::tryFromCallback('p:paid'))->toBe(BotMenuAction::PAYDAY_ARRIVED);
});

it('shows Cobré hoy in the keyboard for every user', function () {
    $labels = array_map(fn (array $row): string => $row[0]['text'], BotMenuAction::keyboard());

    expect($labels)->toContain('Cobré hoy');
});

function runMenuJob(string $action): void
{
    (new ProcessTelegramMenu(F::CHAT, null, $action))->handle(
        app(TelegramChannel::class),
        app(ChannelIdentityResolver::class),
        app(BudgetDigestService::class),
        app(DailyAllowanceService::class),
        app(MonthOverMonthService::class),
        app(SpendingRhythmService::class),
    );
}

it('forwards a defensive Cobré hoy to the payday handling', function () {
    F::enable();
    $this->travelTo(F::at('2026-10-20 12:00'));
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id, 'status' => S::Deferred, 'sent_at' => F::at('2026-10-03 09:00'), 'send_attempts' => 1]);

    runMenuJob('p:paid');

    expect($cycle->fresh()->status)->toBe(S::Pending)
        ->and(F::sentTexts())->toHaveCount(1);
});

it('answers honestly to Cobré hoy when the user has no plan', function () {
    F::enable();
    $user = App\Models\User::factory()->create();
    F::link($user);

    runMenuJob('p:paid');

    expect(F::sentTexts())->toHaveCount(1)
        ->and(F::sentTexts()[0])->toContain('plan');
    expect(PaydayCycle::count())->toBe(0);
});
