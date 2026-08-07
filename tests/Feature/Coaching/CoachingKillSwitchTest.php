<?php

declare(strict_types=1);

use App\DTOs\Coaching\CoachingScope;
use App\Models\Category;
use App\Models\ChannelIdentity;
use App\Models\CoachingObservation;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\CategoryRepositoryContract;
use App\Services\Coaching\FinancialCoachingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function killSwitchUser(): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => (string) $user->id,
    ]);

    return $user;
}

function killSwitchOverBudgetCategory(User $user): Category
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 400,
    ]);

    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 450,
        'date_operation' => now()->toDateTimeString(),
    ]);

    return $category;
}

/**
 * Task 4.5d: `config('coaching.enabled')` is the first line `speak()` executes.
 * The proposal's entire no-deploy rollback ladder rests on this switch (design.md
 * "Rollback ladder", item 1). Slice 4a added the config key with no consumer;
 * this is the batch that gives it one and proves it is honoured, not merely
 * present.
 *
 * A category that is genuinely over budget is used as the fixture on purpose:
 * if the switch were decoration (e.g. speak() happened to return false for an
 * unrelated reason), this fixture would still produce a claim and a send. The
 * mock repository with zero expectations proves the very first thing speak()
 * does is check the switch — it never even reaches CategoryRepository.
 */
it('claims nothing, sends nothing, and never queries a category snapshot when coaching is disabled', function () {
    config(['coaching.enabled' => false]);

    $this->mock(CategoryRepositoryContract::class, function ($mock) {
        $mock->shouldNotReceive('expenseBudgetSnapshotsForMonth');
    });

    Http::fake();

    $user = killSwitchUser();
    killSwitchOverBudgetCategory($user);

    $service = app(FinancialCoachingService::class);

    expect($service->speak($user, CoachingScope::sweep()))->toBeFalse()
        ->and(CoachingObservation::count())->toBe(0);

    Http::assertNothingSent();
});

/**
 * Triangulation: the exact same over-budget fixture, with the switch back at its
 * default (true), DOES claim and send. Without this counterpart the test above
 * could pass for the wrong reason — e.g. speak() always returning false — and
 * would not prove the switch gates anything real.
 */
it('claims and sends normally once the kill switch is back on', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = killSwitchUser();
    killSwitchOverBudgetCategory($user);

    $service = app(FinancialCoachingService::class);

    expect($service->speak($user, CoachingScope::sweep()))->toBeTrue()
        ->and(CoachingObservation::count())->toBe(1);

    Http::assertSentCount(1);
});
