<?php

declare(strict_types=1);

use App\Enums\PaydayCycleStatus as S;
use App\Enums\PaydaySkipReason;
use App\Jobs\ProcessTelegramPaydayAnswer;
use App\Models\PaydayCycle;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaydayFixtures as F;

beforeEach(function () {
    F::enable();
    $this->travelTo(F::at('2026-10-10 12:00'));
});

function pressButton(string $data, ?string $chat = F::CHAT, ?string $callbackId = 'cb-1'): void
{
    app()->call([new ProcessTelegramPaydayAnswer($chat, $callbackId, $data), 'handle']);
}

function answeredCallbacks(): int
{
    return Http::recorded()->filter(fn (array $p): bool => str_ends_with($p[0]->url(), '/answerCallbackQuery'))->count();
}

it('closes the callback first and applies a button press for the owner', function () {
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id]);

    pressButton("p:{$cycle->id}:y");

    expect($cycle->fresh()->status)->toBe(S::Separated)
        ->and(answeredCallbacks())->toBe(1)
        ->and(F::sentTexts())->toHaveCount(1);
});

it('sends the reason prompt with its keyboard after Este mes no', function () {
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id]);

    pressButton("p:{$cycle->id}:n");

    expect($cycle->fresh()->status)->toBe(S::Skipped);
    $request = F::sent()[0];
    expect($request['reply_markup']['inline_keyboard'])->not->toBeEmpty();

    pressButton("p:{$cycle->id}:r:d");

    expect($cycle->fresh()->skip_reason)->toBe(PaydaySkipReason::Debt);
});

it('ignores a chat that is not linked and changes nothing', function () {
    $user = User::factory()->create();
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id]);

    pressButton("p:{$cycle->id}:y", '999');

    expect($cycle->fresh()->status)->toBe(S::Pending)
        ->and(F::sentTexts()[0])->toContain('vincul');
});

it('does not let a chat touch another user\'s cycle', function () {
    F::userWithPlan();
    $other = User::factory()->create();
    $foreign = PaydayCycle::factory()->create(['user_id' => $other->id]);

    pressButton("p:{$foreign->id}:y");

    expect($foreign->fresh()->status)->toBe(S::Pending)
        ->and(F::sentTexts())->toHaveCount(1);
});

it('answers a stale button politely without changing the old cycle', function () {
    $user = F::userWithPlan();
    $old = PaydayCycle::factory()->create(['user_id' => $user->id, 'period_month' => '2026-09-01', 'payday_on' => '2026-09-03']);
    PaydayCycle::factory()->create(['user_id' => $user->id]);

    pressButton("p:{$old->id}:y");

    expect($old->fresh()->status)->toBe(S::Pending)
        ->and(F::sentTexts())->toHaveCount(1);
});

it('answers politely to malformed or unknown callback data', function () {
    F::userWithPlan();

    pressButton('p:garbage');

    expect(F::sentTexts())->toHaveCount(1);
});

it('replies that Cobré hoy is no longer available for an expired month', function () {
    $user = F::userWithPlan();
    PaydayCycle::factory()->create(['user_id' => $user->id, 'period_month' => '2026-09-01', 'payday_on' => '2026-09-03', 'status' => S::Expired]);

    pressButton('p:paid');

    expect(PaydayCycle::count())->toBe(1)
        ->and(F::sentTexts())->toHaveCount(1);
});

it('claims the month early when Cobré hoy comes before payday', function () {
    $user = F::userWithPlan(['payday_day' => 25]);

    pressButton('p:paid');

    expect(PaydayCycle::where('user_id', $user->id)->count())->toBe(1)
        ->and(F::sentTexts()[0])->toContain('ahorro');
});

it('tells a user without a plan, or with the feature off, to set it up on the web', function () {
    $user = User::factory()->create();
    F::link($user);

    pressButton('p:paid');
    config()->set('payday.enabled', false);
    pressButton('p:paid');

    expect(F::sentTexts())->toHaveCount(2)
        ->and(PaydayCycle::count())->toBe(0);
});

it('works without a callback query id', function () {
    $user = F::userWithPlan();
    $cycle = PaydayCycle::factory()->create(['user_id' => $user->id]);

    pressButton("p:{$cycle->id}:w", F::CHAT, null);

    expect($cycle->fresh()->status)->toBe(S::Deferred)
        ->and(answeredCallbacks())->toBe(0);
});
