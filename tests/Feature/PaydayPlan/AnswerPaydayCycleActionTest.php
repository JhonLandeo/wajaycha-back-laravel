<?php

declare(strict_types=1);

use App\Actions\PaydayPlan\AnswerPaydayCycleAction;
use App\DTOs\PaydayPlan\PaydayCallback;
use App\Enums\PaydayAnswer;
use App\Enums\PaydayCycleStatus as S;
use App\Enums\PaydaySkipReason;
use App\Models\PaydayCycle;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->action = app(AnswerPaydayCycleAction::class);
    $this->user = User::factory()->create();
    $this->cycle = PaydayCycle::factory()->create(['user_id' => $this->user->id, 'status' => S::Pending, 'sent_at' => CarbonImmutable::parse('2026-10-03 09:00', 'America/Lima')]);
    $this->now = CarbonImmutable::parse('2026-10-05 10:00', 'America/Lima');
});

function answerTo(object $t, PaydayAnswer $answer, ?PaydayCycle $cycle = null, ?User $user = null): App\DTOs\PaydayPlan\PaydayMessage
{
    return $t->action->execute(($user ?? $t->user)->id, PaydayCallback::answer(($cycle ?? $t->cycle)->id, $answer), $t->now);
}

it('records Ya separé', function () {
    $message = answerTo($this, PaydayAnswer::Separated);

    $fresh = $this->cycle->fresh();
    expect($fresh->status)->toBe(S::Separated)
        ->and($fresh->answered_at->equalTo($this->now))->toBeTrue()
        ->and($message->text)->toBe('Anotado: separaste tu ahorro de este mes.');
});

it('records Este mes no and asks the reason, then stores it', function () {
    $message = answerTo($this, PaydayAnswer::Skip);

    expect($this->cycle->fresh()->status)->toBe(S::Skipped)
        ->and($this->cycle->fresh()->skip_reason)->toBeNull()
        ->and(array_column(array_merge(...$message->keyboard), 'callback_data'))
        ->toBe(["p:{$this->cycle->id}:r:u", "p:{$this->cycle->id}:r:d", "p:{$this->cycle->id}:r:s", "p:{$this->cycle->id}:r:o"]);

    $reply = $this->action->execute($this->user->id, PaydayCallback::reason($this->cycle->id, PaydaySkipReason::Debt), $this->now);

    expect($this->cycle->fresh()->skip_reason)->toBe(PaydaySkipReason::Debt)
        ->and($this->cycle->fresh()->status)->toBe(S::Skipped)
        ->and($reply->text)->toContain('Deuda');
});

it('records Todavía no cobré and offers Cobré hoy', function () {
    $message = answerTo($this, PaydayAnswer::Wait);

    expect($this->cycle->fresh()->status)->toBe(S::Deferred)
        ->and($message->keyboard[0][0]['callback_data'])->toBe('p:paid');
});

it('does nothing the second time Ya separé is tapped', function () {
    answerTo($this, PaydayAnswer::Separated);
    $firstAnsweredAt = $this->cycle->fresh()->answered_at;
    $this->now = $this->now->addHour();

    $second = answerTo($this, PaydayAnswer::Separated);

    expect($second->text)->toBe('Ya registraste tu respuesta de este mes.')
        ->and($this->cycle->fresh()->answered_at->equalTo($firstAnsweredAt))->toBeTrue()
        ->and($this->cycle->fresh()->status)->toBe(S::Separated);
});

it('lets the answer change while the month is current', function () {
    answerTo($this, PaydayAnswer::Separated);

    answerTo($this, PaydayAnswer::Skip);
    expect($this->cycle->fresh()->status)->toBe(S::Skipped);

    $this->action->execute($this->user->id, PaydayCallback::reason($this->cycle->id, PaydaySkipReason::Short), $this->now);
    answerTo($this, PaydayAnswer::Separated);

    expect($this->cycle->fresh()->status)->toBe(S::Separated)
        ->and($this->cycle->fresh()->skip_reason)->toBeNull();
});

it('shows the reason prompt again when Este mes no is repeated without a reason', function () {
    answerTo($this, PaydayAnswer::Skip);

    $again = answerTo($this, PaydayAnswer::Skip);

    expect($again->keyboard)->toHaveCount(4);
});

it('ignores a foreign cycle without changing it or leaking anything', function () {
    $stranger = User::factory()->create();

    $message = answerTo($this, PaydayAnswer::Separated, user: $stranger);

    expect($message->text)->toBe('Ese botón ya no aplica.')
        ->and($this->cycle->fresh()->status)->toBe(S::Pending);
});

it('answers the same way for a cycle id that does not exist', function () {
    $message = $this->action->execute($this->user->id, PaydayCallback::answer(999999, PaydayAnswer::Separated), $this->now);

    expect($message->text)->toBe('Ese botón ya no aplica.');
});

it('rejects a button of an older cycle once a newer one exists', function () {
    $newer = PaydayCycle::factory()->create(['user_id' => $this->user->id, 'period_month' => '2026-11-01', 'payday_on' => '2026-11-03']);
    $this->now = CarbonImmutable::parse('2026-11-05 10:00', 'America/Lima');

    $message = answerTo($this, PaydayAnswer::Separated);

    expect($message->text)->toBe('Ese botón ya no aplica.')
        ->and($this->cycle->fresh()->status)->toBe(S::Pending)
        ->and($newer->fresh()->status)->toBe(S::Pending);
});

it('rejects an answer once the month is over even if no newer cycle exists yet', function () {
    $this->now = CarbonImmutable::parse('2026-11-01 08:00', 'America/Lima');

    $message = answerTo($this, PaydayAnswer::Separated);

    expect($message->text)->toBe('Ese botón ya no aplica.')
        ->and($this->cycle->fresh()->status)->toBe(S::Pending);
});

it('rejects answers to an expired cycle', function () {
    $this->cycle->update(['status' => S::Expired]);

    expect(answerTo($this, PaydayAnswer::Separated)->text)->toBe('Ese botón ya no aplica.')
        ->and($this->cycle->fresh()->status)->toBe(S::Expired);
});

it('rejects a reason on a cycle that is not skipped', function () {
    $reply = $this->action->execute($this->user->id, PaydayCallback::reason($this->cycle->id, PaydaySkipReason::Debt), $this->now);

    expect($reply->text)->toBe('Ese botón ya no aplica.')
        ->and($this->cycle->fresh()->skip_reason)->toBeNull();
});

it('rejects a reason for a foreign cycle', function () {
    $this->cycle->update(['status' => S::Skipped]);

    $reply = $this->action->execute(User::factory()->create()->id, PaydayCallback::reason($this->cycle->id, PaydaySkipReason::Debt), $this->now);

    expect($reply->text)->toBe('Ese botón ya no aplica.')
        ->and($this->cycle->fresh()->skip_reason)->toBeNull();
});
