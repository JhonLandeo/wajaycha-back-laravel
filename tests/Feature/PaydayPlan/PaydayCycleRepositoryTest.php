<?php

declare(strict_types=1);

use App\DTOs\PaydayPlan\PaydayPlanSnapshot;
use App\Enums\PaydayCycleStatus as S;
use App\Enums\PaydaySkipReason;
use App\Models\PaydayCycle;
use App\Models\User;
use App\Repositories\Contracts\PaydayCycleRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->cycles = app(PaydayCycleRepositoryContract::class);
    $this->user = User::factory()->create();
    $this->snapshot = PaydayPlanSnapshot::fromDeclaration(3, '3000.00', 10);
    $this->month = CarbonImmutable::parse('2026-10-01', 'America/Lima');
});

function claimCycle(object $t, ?User $user = null, ?CarbonImmutable $month = null): ?PaydayCycle
{
    return $t->cycles->claim(
        ($user ?? $t->user)->id,
        $month ?? $t->month,
        ($month ?? $t->month)->addDays(2),
        $t->snapshot,
    );
}

it('is bound to the contract', function () {
    expect($this->cycles)->toBeInstanceOf(App\Repositories\PaydayCycleRepository::class);
});

it('claims a cycle once with the snapshot, as the first attempt', function () {
    $cycle = claimCycle($this);

    expect($cycle)->not->toBeNull()
        ->and($cycle->status)->toBe(S::Pending)
        ->and($cycle->send_attempts)->toBe(1)
        ->and($cycle->sent_at)->toBeNull()
        ->and($cycle->period_month->toDateString())->toBe('2026-10-01')
        ->and($cycle->payday_on->toDateString())->toBe('2026-10-03')
        ->and($cycle->plan_snapshot['savings_amount'])->toBe('300.00');
});

it('answers null to a second claim of the same month, even inside a transaction', function () {
    claimCycle($this);

    $second = DB::transaction(fn () => claimCycle($this));

    expect($second)->toBeNull()
        ->and(PaydayCycle::query()->count())->toBe(1);

    // The surrounding transaction survived the unique violation.
    expect(claimCycle($this, null, CarbonImmutable::parse('2026-11-01', 'America/Lima')))->not->toBeNull();
});

it('lets a different user claim the same month', function () {
    claimCycle($this);

    expect(claimCycle($this, User::factory()->create()))->not->toBeNull();
});

it('finds the cycle of a month and nothing for another', function () {
    $cycle = claimCycle($this);

    expect($this->cycles->findForMonth($this->user->id, $this->month)->id)->toBe($cycle->id)
        ->and($this->cycles->findForMonth($this->user->id, CarbonImmutable::parse('2026-11-01')))->toBeNull()
        ->and($this->cycles->findForMonth(User::factory()->create()->id, $this->month))->toBeNull();
});

it('finds an owned cycle only for its owner', function () {
    $cycle = claimCycle($this);
    $stranger = User::factory()->create();

    expect($this->cycles->findOwned($this->user->id, $cycle->id)->id)->toBe($cycle->id)
        ->and($this->cycles->findOwned($stranger->id, $cycle->id))->toBeNull()
        ->and($this->cycles->findOwned($this->user->id, $cycle->id + 999))->toBeNull();
});

it('returns the latest cycle by month', function () {
    claimCycle($this, null, CarbonImmutable::parse('2026-09-01', 'America/Lima'));
    $latest = claimCycle($this, null, CarbonImmutable::parse('2026-10-01', 'America/Lima'));
    claimCycle($this, User::factory()->create(), CarbonImmutable::parse('2026-12-01', 'America/Lima'));

    expect($this->cycles->latestFor($this->user->id)->id)->toBe($latest->id)
        ->and($this->cycles->latestFor(User::factory()->create()->id))->toBeNull();
});

it('marks a cycle sent and arms the reminder', function () {
    $cycle = claimCycle($this);
    $sentAt = CarbonImmutable::parse('2026-10-03 09:00', 'America/Lima');

    $this->cycles->markSent($cycle->id, $sentAt, CarbonImmutable::parse('2026-10-04', 'America/Lima'));

    $fresh = $cycle->fresh();
    expect($fresh->status)->toBe(S::Pending)
        ->and($fresh->sent_at->equalTo($sentAt))->toBeTrue()
        ->and($fresh->reminder_due_on->toDateString())->toBe('2026-10-04');
});

it('keeps the existing reminder date when no new one is given', function () {
    $cycle = claimCycle($this);
    $this->cycles->markSent($cycle->id, CarbonImmutable::now(), CarbonImmutable::parse('2026-10-04', 'America/Lima'));

    $this->cycles->markSent($cycle->id, CarbonImmutable::now(), null);

    expect($cycle->fresh()->reminder_due_on->toDateString())->toBe('2026-10-04');
});

it('moves a send_failed or deferred cycle back to pending when it is sent', function (S $from) {
    $cycle = claimCycle($this);
    $cycle->update(['status' => $from]);

    $this->cycles->markSent($cycle->id, CarbonImmutable::now(), null);

    expect($cycle->fresh()->status)->toBe(S::Pending);
})->with([[S::SendFailed], [S::Deferred]]);

it('never reopens an answered cycle when a late send is recorded', function (S $answered) {
    $cycle = claimCycle($this);
    $cycle->update(['status' => $answered]);

    $this->cycles->markSent($cycle->id, CarbonImmutable::now(), null);

    expect($cycle->fresh()->status)->toBe($answered);
})->with([[S::Separated], [S::Skipped], [S::Expired]]);

it('marks a failed send', function () {
    $cycle = claimCycle($this);

    $this->cycles->markSendFailed($cycle->id);

    expect($cycle->fresh()->status)->toBe(S::SendFailed);
});

it('does not turn an answered cycle into send_failed', function () {
    $cycle = claimCycle($this);
    $cycle->update(['status' => S::Separated]);

    $this->cycles->markSendFailed($cycle->id);

    expect($cycle->fresh()->status)->toBe(S::Separated);
});

it('lets exactly one caller own a new send attempt', function () {
    $cycle = claimCycle($this);
    $cycle->update(['status' => S::SendFailed]);

    $first = $this->cycles->beginAttempt($cycle->fresh());
    $stale = $this->cycles->beginAttempt($cycle);   // still holds the old attempts value

    expect($first)->toBeTrue()
        ->and($stale)->toBeFalse()
        ->and($cycle->fresh()->send_attempts)->toBe(2);
});

it('owns an attempt for a claimed-but-never-sent pending cycle', function () {
    $cycle = claimCycle($this);

    expect($this->cycles->beginAttempt($cycle->fresh()))->toBeTrue();
});

it('refuses an attempt on answered or expired cycles', function (S $status) {
    $cycle = claimCycle($this);
    $cycle->update(['status' => $status]);

    expect($this->cycles->beginAttempt($cycle->fresh()))->toBeFalse()
        ->and($cycle->fresh()->send_attempts)->toBe(1);
})->with([[S::Separated], [S::Skipped], [S::Expired]]);

it('claims the reminder once, only for a pending cycle due today', function () {
    $cycle = claimCycle($this);
    $this->cycles->markSent($cycle->id, CarbonImmutable::now(), CarbonImmutable::parse('2026-10-04', 'America/Lima'));
    $today = CarbonImmutable::parse('2026-10-04', 'America/Lima');

    expect($this->cycles->claimReminder($cycle->id, $today))->toBeTrue()
        ->and($this->cycles->claimReminder($cycle->id, $today))->toBeFalse()
        ->and($cycle->fresh()->reminded_at)->not->toBeNull();
});

it('does not claim the reminder on the wrong day or for a non-pending cycle', function () {
    $cycle = claimCycle($this);
    $this->cycles->markSent($cycle->id, CarbonImmutable::now(), CarbonImmutable::parse('2026-10-04', 'America/Lima'));

    expect($this->cycles->claimReminder($cycle->id, CarbonImmutable::parse('2026-10-03', 'America/Lima')))->toBeFalse()
        ->and($this->cycles->claimReminder($cycle->id, CarbonImmutable::parse('2026-10-05', 'America/Lima')))->toBeFalse();

    $cycle->update(['status' => S::Deferred]);
    expect($this->cycles->claimReminder($cycle->id, CarbonImmutable::parse('2026-10-04', 'America/Lima')))->toBeFalse();
});

it('lists only the pending cycles due for a reminder today', function () {
    $today = CarbonImmutable::parse('2026-10-04', 'America/Lima');
    $due = claimCycle($this);
    $this->cycles->markSent($due->id, CarbonImmutable::now(), $today);

    $other = claimCycle($this, User::factory()->create());
    $this->cycles->markSent($other->id, CarbonImmutable::now(), $today);
    $other->update(['status' => S::Skipped]);

    $reminded = claimCycle($this, User::factory()->create());
    $this->cycles->markSent($reminded->id, CarbonImmutable::now(), $today);
    $this->cycles->claimReminder($reminded->id, $today);

    $tomorrow = claimCycle($this, User::factory()->create());
    $this->cycles->markSent($tomorrow->id, CarbonImmutable::now(), $today->addDay());

    expect($this->cycles->dueForReminder($today)->pluck('id')->all())->toBe([$due->id]);
});

it('expires open and undelivered cycles of earlier months and leaves the rest', function () {
    $sept = CarbonImmutable::parse('2026-09-01', 'America/Lima');
    $pending = claimCycle($this, null, $sept);
    $deferred = claimCycle($this, User::factory()->create(), $sept);
    $deferred->update(['status' => S::Deferred]);
    $failed = claimCycle($this, User::factory()->create(), $sept);
    $failed->update(['status' => S::SendFailed]);
    $separated = claimCycle($this, User::factory()->create(), $sept);
    $separated->update(['status' => S::Separated]);
    $current = claimCycle($this, User::factory()->create());

    $count = $this->cycles->expirePriorMonths($this->month);

    expect($count)->toBe(3)
        ->and($pending->fresh()->status)->toBe(S::Expired)
        ->and($deferred->fresh()->status)->toBe(S::Expired)
        ->and($failed->fresh()->status)->toBe(S::Expired)
        ->and($separated->fresh()->status)->toBe(S::Separated)
        ->and($current->fresh()->status)->toBe(S::Pending);
});

it('applies an answer only from the status the caller saw', function () {
    $cycle = claimCycle($this);
    $at = CarbonImmutable::parse('2026-10-05 10:00', 'America/Lima');

    $applied = $this->cycles->applyAnswer($cycle->id, S::Pending, S::Separated, $at);
    $lost = $this->cycles->applyAnswer($cycle->id, S::Pending, S::Skipped, $at);

    $fresh = $cycle->fresh();
    expect($applied)->toBeTrue()
        ->and($lost)->toBeFalse()
        ->and($fresh->status)->toBe(S::Separated)
        ->and($fresh->answered_at->equalTo($at))->toBeTrue();
});

it('clears a stale skip reason when the answer changes', function () {
    $cycle = claimCycle($this);
    $cycle->update(['status' => S::Skipped, 'skip_reason' => PaydaySkipReason::Debt]);

    $this->cycles->applyAnswer($cycle->id, S::Skipped, S::Separated, CarbonImmutable::now());

    expect($cycle->fresh()->skip_reason)->toBeNull();
});

it('stores a skip reason only on a skipped cycle', function () {
    $cycle = claimCycle($this);
    $at = CarbonImmutable::parse('2026-10-05 10:00', 'America/Lima');

    expect($this->cycles->setSkipReason($cycle->id, PaydaySkipReason::Debt, $at))->toBeFalse();

    $cycle->update(['status' => S::Skipped]);

    expect($this->cycles->setSkipReason($cycle->id, PaydaySkipReason::Debt, $at))->toBeTrue()
        ->and($cycle->fresh()->skip_reason)->toBe(PaydaySkipReason::Debt);
});

it('stores an instant given in UTC without shifting it', function () {
    $cycle = claimCycle($this);
    $sentAt = CarbonImmutable::parse('2026-10-03 14:00:00', 'UTC');

    $this->cycles->markSent($cycle->id, $sentAt, null);

    expect($cycle->fresh()->sent_at->equalTo($sentAt))->toBeTrue();
});
