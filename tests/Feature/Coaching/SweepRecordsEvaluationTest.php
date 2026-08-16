<?php

declare(strict_types=1);

use App\DTOs\Coaching\CoachingScope;
use App\Models\Category;
use App\Models\ChannelIdentity;
use App\Models\CoachingEvaluation;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Coaching\FinancialCoachingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(fn () => Http::response(['ok' => true], 200));
});

function evaluatedUser(): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => (string) $user->id,
    ]);

    return $user;
}

/**
 * A category with spending in the current month. The default figures stay well
 * under budget on every possible day of the month — 50 spent against 400 cannot
 * project past 440 even on the first day — so "clean" here never depends on when
 * the suite happens to run.
 */
function evaluatedCategoryFor(User $user, float $budget = 400.0, float $spent = 50.0): Category
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
        'amount' => $spent,
        'date_operation' => now()->toDateTimeString(),
    ]);

    return $category;
}

/**
 * The whole reason the table exists. Before this, a month the coach stayed quiet
 * about left no trace at all, so "three months clean" and "three months nobody
 * looked" were the same query result.
 */
it('records a clean month even though it sends nothing', function () {
    $user = evaluatedUser();
    $category = evaluatedCategoryFor($user);

    $spoke = app(FinancialCoachingService::class)->speak($user, CoachingScope::sweep());

    expect($spoke)->toBeFalse()
        ->and(CoachingEvaluation::count())->toBe(1)
        ->and(CoachingEvaluation::sole()->outcome)->toBe(CoachingEvaluation::OUTCOME_CLEAN)
        ->and(CoachingEvaluation::sole()->category_id)->toBe($category->id);
});

it('records the band it reached on a month it did speak about', function () {
    $user = evaluatedUser();
    evaluatedCategoryFor($user, budget: 400.0, spent: 450.0);

    $spoke = app(FinancialCoachingService::class)->speak($user, CoachingScope::sweep());

    expect($spoke)->toBeTrue()
        ->and(CoachingEvaluation::sole()->outcome)->toBe('over_budget');
});

it('records a category with spending and no budget as blind', function () {
    $user = evaluatedUser();
    evaluatedCategoryFor($user, budget: 0.0, spent: 150.0);

    app(FinancialCoachingService::class)->speak($user, CoachingScope::sweep());

    expect(CoachingEvaluation::sole()->outcome)->toBe(CoachingEvaluation::OUTCOME_BLIND);
});

it('rewrites the same month instead of piling up one row per night', function () {
    $user = evaluatedUser();
    evaluatedCategoryFor($user);

    $service = app(FinancialCoachingService::class);
    $service->speak($user, CoachingScope::sweep());
    $service->speak($user, CoachingScope::sweep());
    $service->speak($user, CoachingScope::sweep());

    expect(CoachingEvaluation::count())->toBe(1);
});

/**
 * `--dry-run` composes what would be said without claiming it. A preview that
 * writes the evaluation ledger would be a preview with a side effect, which is
 * the same objection that keeps it away from `claim()`.
 */
it('records nothing on a preview', function () {
    $user = evaluatedUser();
    evaluatedCategoryFor($user, budget: 400.0, spent: 450.0);

    app(FinancialCoachingService::class)->preview($user, CoachingScope::sweep());

    expect(CoachingEvaluation::count())->toBe(0);
});

/**
 * The capture-time check looks at exactly one category, so it has no basis to
 * record a verdict for the month's other categories — and recording only its own
 * would leave a month half-written by whichever transaction happened to arrive.
 * The nightly sweep is the only writer.
 */
it('records nothing on a capture-time check', function () {
    $user = evaluatedUser();
    $category = evaluatedCategoryFor($user, budget: 400.0, spent: 450.0);

    app(FinancialCoachingService::class)->speak($user, CoachingScope::forCategory($category->id, 450.0));

    expect(CoachingEvaluation::count())->toBe(0);
});

/**
 * A switched-off coach did not look, and must not leave a row saying it did.
 * `speak()` returns on its first line, before any snapshot query, so this is
 * inherited rather than re-implemented — the test pins it because the recording
 * step sits close enough to that early return to be moved above it by accident.
 */
it('records nothing while the kill switch is off', function () {
    config()->set('coaching.enabled', false);

    $user = evaluatedUser();
    evaluatedCategoryFor($user);

    app(FinancialCoachingService::class)->speak($user, CoachingScope::sweep());

    expect(CoachingEvaluation::count())->toBe(0);
});

/**
 * `max_observations_per_message` bounds the message, not the record. A fourth
 * category that went over budget did so whether or not there was room to say it,
 * and `COACHING_MAX_OBSERVATIONS=0` is a documented way to mute the coach — it
 * must not also blind the ledger.
 */
it('records every category that crossed, past the message cap', function () {
    config()->set('coaching.max_observations_per_message', 1);

    $user = evaluatedUser();
    evaluatedCategoryFor($user, budget: 400.0, spent: 450.0);
    evaluatedCategoryFor($user, budget: 300.0, spent: 380.0);
    evaluatedCategoryFor($user, budget: 200.0, spent: 260.0);

    app(FinancialCoachingService::class)->speak($user, CoachingScope::sweep());

    expect(CoachingEvaluation::where('outcome', 'over_budget')->count())->toBe(3);
});
