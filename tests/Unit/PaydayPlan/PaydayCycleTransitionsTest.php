<?php

declare(strict_types=1);

use App\Enums\PaydayAnswer;
use App\Enums\PaydayCycleStatus as S;
use App\Services\PaydayPlan\PaydayCycleTransitions;

it('applies each answer to an open cycle', function (S $from, PaydayAnswer $answer, ?S $to) {
    expect((new PaydayCycleTransitions)->afterAnswer($from, $answer, true))->toBe($to);
})->with([
    'pending yes' => [S::Pending, PaydayAnswer::Separated, S::Separated],
    'pending no' => [S::Pending, PaydayAnswer::Skip, S::Skipped],
    'pending wait' => [S::Pending, PaydayAnswer::Wait, S::Deferred],
    'deferred yes' => [S::Deferred, PaydayAnswer::Separated, S::Separated],
    'deferred no' => [S::Deferred, PaydayAnswer::Skip, S::Skipped],
    'deferred wait is idempotent' => [S::Deferred, PaydayAnswer::Wait, S::Deferred],
    'separated yes is idempotent' => [S::Separated, PaydayAnswer::Separated, S::Separated],
    'separated can change to skipped' => [S::Separated, PaydayAnswer::Skip, S::Skipped],
    'skipped can change to separated' => [S::Skipped, PaydayAnswer::Separated, S::Separated],
    'skipped no is idempotent' => [S::Skipped, PaydayAnswer::Skip, S::Skipped],
    'separated cannot wait' => [S::Separated, PaydayAnswer::Wait, null],
    'skipped cannot wait' => [S::Skipped, PaydayAnswer::Wait, null],
    'expired accepts nothing yes' => [S::Expired, PaydayAnswer::Separated, null],
    'expired accepts nothing no' => [S::Expired, PaydayAnswer::Skip, null],
    'expired accepts nothing wait' => [S::Expired, PaydayAnswer::Wait, null],
    'send_failed cannot be answered' => [S::SendFailed, PaydayAnswer::Separated, null],
]);

it('accepts no answer once the month is over', function () {
    foreach ([S::Pending, S::Deferred, S::Separated, S::Skipped] as $status) {
        foreach (PaydayAnswer::cases() as $answer) {
            expect((new PaydayCycleTransitions)->afterAnswer($status, $answer, false))->toBeNull();
        }
    }
});

it('lets the reason be set only on a skipped cycle', function () {
    $t = new PaydayCycleTransitions;

    expect($t->acceptsReason(S::Skipped, true))->toBeTrue()
        ->and($t->acceptsReason(S::Skipped, false))->toBeFalse()
        ->and($t->acceptsReason(S::Pending, true))->toBeFalse()
        ->and($t->acceptsReason(S::Deferred, true))->toBeFalse()
        ->and($t->acceptsReason(S::Separated, true))->toBeFalse()
        ->and($t->acceptsReason(S::Expired, true))->toBeFalse()
        ->and($t->acceptsReason(S::SendFailed, true))->toBeFalse();
});

it('says what Cobré hoy does for each state', function (S $from, ?S $to) {
    expect((new PaydayCycleTransitions)->afterPaid($from))->toBe($to);
})->with([
    'deferred resends and becomes pending' => [S::Deferred, S::Pending],
    'pending resends and stays pending' => [S::Pending, S::Pending],
    'send_failed resends' => [S::SendFailed, S::Pending],
    'separated is already answered' => [S::Separated, null],
    'skipped is already answered' => [S::Skipped, null],
    'expired is gone' => [S::Expired, null],
]);

it('marks a cycle expired at month end only when it was still open or undelivered', function (S $from, ?S $to) {
    expect((new PaydayCycleTransitions)->afterMonthEnd($from))->toBe($to);
})->with([
    [S::Pending, S::Expired],
    [S::Deferred, S::Expired],
    [S::SendFailed, S::Expired],
    [S::Separated, null],
    [S::Skipped, null],
    [S::Expired, null],
]);

it('retries a failed or never-sent plan only inside the grace window', function (S $status, bool $sent, bool $inGrace, bool $expected) {
    expect((new PaydayCycleTransitions)->isRetryable($status, $sent, $inGrace))->toBe($expected);
})->with([
    'send_failed in grace' => [S::SendFailed, false, true, true],
    'send_failed after grace' => [S::SendFailed, false, false, false],
    'pending never sent in grace' => [S::Pending, false, true, true],
    'pending never sent after grace' => [S::Pending, false, false, false],
    'pending already sent is not a retry' => [S::Pending, true, true, false],
    'deferred is never retried' => [S::Deferred, false, true, false],
    'separated is never retried' => [S::Separated, false, true, false],
    'skipped is never retried' => [S::Skipped, false, true, false],
    'expired is never retried' => [S::Expired, false, true, false],
]);

it('re-arms the reminder on a delivery only while it has not been sent', function () {
    $t = new PaydayCycleTransitions;

    expect($t->rearmsReminder(false))->toBeTrue()
        ->and($t->rearmsReminder(true))->toBeFalse();
});

it('sends the reminder only for a pending cycle that was never reminded', function (S $status, bool $reminded, bool $expected) {
    expect((new PaydayCycleTransitions)->wantsReminder($status, $reminded))->toBe($expected);
})->with([
    'pending fresh' => [S::Pending, false, true],
    'pending already reminded' => [S::Pending, true, false],
    'deferred never' => [S::Deferred, false, false],
    'separated never' => [S::Separated, false, false],
    'skipped never' => [S::Skipped, false, false],
    'expired never' => [S::Expired, false, false],
    'send_failed never' => [S::SendFailed, false, false],
]);
