<?php

declare(strict_types=1);

use App\DTOs\Coaching\CoachingScope;
use App\Models\Category;
use App\Models\ChannelIdentity;
use App\Models\CoachingObservation;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Coaching\FinancialCoachingService;
use App\Services\Coaching\SpokenObservationLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function sweepCoachedUser(): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => (string) $user->id,
    ]);

    return $user;
}

function sweepOverBudgetCategoryFor(User $user, float $amount = 450.0, float $budget = 400.0): Category
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => $budget,
    ]);

    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => $amount,
        'date_operation' => now()->toDateTimeString(),
    ]);

    return $category;
}

/**
 * D7: the observation is claimed BEFORE `reply()` is called. If the send
 * happened first, the sweep and the capture-time check could both send before
 * either recorded — exactly the duplicate the unique index exists to prevent.
 *
 * `SpokenObservationLedger::claim()` is mocked purely to record WHEN it was
 * called relative to the outbound HTTP call; the real ledger's own insert
 * semantics are already proven by Phase 3's SpokenObservationLedgerTest.
 */
it('claims before it sends, always (D7)', function () {
    $user = sweepCoachedUser();
    sweepOverBudgetCategoryFor($user);

    $callOrder = [];

    $this->mock(SpokenObservationLedger::class, function ($mock) use (&$callOrder) {
        $mock->shouldReceive('highestBandFor')->andReturn(null);
        $mock->shouldReceive('claim')->once()->andReturnUsing(function () use (&$callOrder) {
            $callOrder[] = 'claim';

            return true;
        });
        $mock->shouldReceive('confirmSent')->andReturnNull();
    });

    Http::fake(function () use (&$callOrder) {
        $callOrder[] = 'send';

        return Http::response(['ok' => true], 200);
    });

    $service = app(FinancialCoachingService::class);
    $service->speak($user, CoachingScope::sweep());

    expect($callOrder)->toBe(['claim', 'send']);
});

/**
 * D7's accepted cost: a send that fails after a successful claim silences that
 * band for the rest of the month. The row must NOT be un-claimed — it stays
 * spoken.
 *
 * On `sent_at`: design.md §7's failure table is explicit — "Telegram
 * sendMessage non-2xx ... sent_at is still set — it only proves the call
 * returned." `TelegramChannel::reply()` (untouched, per design.md) logs and
 * swallows a non-2xx response and returns `void` without throwing, so
 * `FinancialCoachingService` cannot distinguish "the send failed" from "the
 * send succeeded" without either touching the capture port (explicitly
 * forbidden) or inspecting HTTP internals the port already hides. This
 * directly contradicts this task's own literal wording ("sent_at stays
 * NULL") and the orchestrator prompt's restatement of it — flagged as a design
 * conflict in the apply return summary rather than silently picked either way.
 * Implemented per design.md §7/D7, the more detailed and explicit of the two,
 * and the one the orchestrator named as required reading for this exact point.
 */
it('does not un-claim when the send fails — the band stays spoken', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $user = sweepCoachedUser();
    sweepOverBudgetCategoryFor($user);

    $service = app(FinancialCoachingService::class);
    $result = $service->speak($user, CoachingScope::sweep());

    expect($result)->toBeTrue()
        ->and(CoachingObservation::count())->toBe(1);

    $observation = CoachingObservation::sole();

    expect($observation->band)->toBe('over_budget')
        ->and($observation->spoken_at)->not->toBeNull()
        // See docblock above: set per design.md §7, not left NULL.
        ->and($observation->sent_at)->not->toBeNull();
});

/**
 * The sweep and the capture-time check share one ledger, so evaluating the
 * same band twice — regardless of which entry point does it — produces
 * exactly one message. The capture-time check is invoked with the SAME
 * transaction the sweep already saw, which its own counterfactual would
 * otherwise judge as "crossing the line"; the pre-claim ladder read is what
 * stops the second voice here.
 */
it('produces exactly one message when the sweep and the capture-time check both evaluate the same band', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = sweepCoachedUser();
    $category = sweepOverBudgetCategoryFor($user, amount: 450.0, budget: 400.0);

    $service = app(FinancialCoachingService::class);

    $fromSweep = $service->speak($user, CoachingScope::sweep());
    $fromCapture = $service->speak($user, CoachingScope::forCategory($category->id, 450.0));

    expect($fromSweep)->toBeTrue()
        ->and($fromCapture)->toBeFalse()
        ->and(CoachingObservation::count())->toBe(1);

    Http::assertSentCount(1);
});

it('returns false and sends nothing when nothing crosses the speaking threshold', function () {
    Http::fake();

    $user = sweepCoachedUser();
    Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 1000,
    ]);

    $service = app(FinancialCoachingService::class);

    expect($service->speak($user, CoachingScope::sweep()))->toBeFalse()
        ->and(CoachingObservation::count())->toBe(0);

    Http::assertNothingSent();
});
