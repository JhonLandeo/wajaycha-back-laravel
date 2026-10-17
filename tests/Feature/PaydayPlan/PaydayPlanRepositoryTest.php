<?php

declare(strict_types=1);

use App\Models\PaydayPlan;
use App\Models\User;
use App\Repositories\Contracts\PaydayPlanRepositoryContract;

beforeEach(function () {
    $this->plans = app(PaydayPlanRepositoryContract::class);
});

it('is bound to the contract', function () {
    expect($this->plans)->toBeInstanceOf(App\Repositories\PaydayPlanRepository::class);
});

it('finds nothing for a user without a plan', function () {
    expect($this->plans->findForUser(User::factory()->create()->id))->toBeNull();
});

it('creates the plan on first save and replaces it on the second', function () {
    $user = User::factory()->create();

    $first = $this->plans->replaceForUser($user->id, ['payday_day' => 3, 'declared_salary' => '3000.00', 'savings_percent' => 10, 'is_active' => true]);
    $second = $this->plans->replaceForUser($user->id, ['payday_day' => 15, 'declared_salary' => '3500.50', 'savings_percent' => 20, 'is_active' => false]);

    expect(PaydayPlan::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->payday_day)->toBe(15)
        ->and($second->declared_salary)->toBe('3500.50')
        ->and($second->savings_percent)->toBe(20)
        ->and($second->is_active)->toBeFalse()
        ->and($this->plans->findForUser($user->id)->payday_day)->toBe(15);
});

it('never touches another user\'s plan', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    PaydayPlan::factory()->create(['user_id' => $b->id, 'payday_day' => 7]);

    $this->plans->replaceForUser($a->id, ['payday_day' => 3, 'declared_salary' => '1000.00', 'savings_percent' => 5, 'is_active' => true]);

    expect($this->plans->findForUser($b->id)->payday_day)->toBe(7)
        ->and($this->plans->findForUser($a->id)->payday_day)->toBe(3);
});

it('lists only active plans for the daily run', function () {
    $active = PaydayPlan::factory()->create(['is_active' => true]);
    PaydayPlan::factory()->create(['is_active' => false]);

    $ids = $this->plans->activePlans()->map(fn (PaydayPlan $p) => $p->id)->all();

    expect($ids)->toBe([$active->id]);
});
