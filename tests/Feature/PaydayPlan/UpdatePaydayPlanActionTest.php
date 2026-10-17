<?php

declare(strict_types=1);

use App\Actions\PaydayPlan\UpdatePaydayPlanAction;
use App\Models\PaydayPlan;
use App\Models\User;

it('creates the plan from validated input', function () {
    $user = User::factory()->create();

    $plan = app(UpdatePaydayPlanAction::class)->execute($user->id, [
        'payday_day' => 3, 'declared_salary' => '3000', 'savings_percent' => 10, 'is_active' => true,
    ]);

    expect($plan->user_id)->toBe($user->id)
        ->and($plan->payday_day)->toBe(3)
        ->and($plan->declared_salary)->toBe('3000.00')
        ->and($plan->savings_percent)->toBe(10)
        ->and($plan->is_active)->toBeTrue();
});

it('replaces every field of an existing plan, including deactivating it', function () {
    $user = User::factory()->create();
    PaydayPlan::factory()->create(['user_id' => $user->id]);

    $plan = app(UpdatePaydayPlanAction::class)->execute($user->id, [
        'payday_day' => 28, 'declared_salary' => 4500.5, 'savings_percent' => 25, 'is_active' => false,
    ]);

    expect(PaydayPlan::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($plan->payday_day)->toBe(28)
        ->and($plan->declared_salary)->toBe('4500.50')
        ->and($plan->savings_percent)->toBe(25)
        ->and($plan->is_active)->toBeFalse();
});

it('does not reach another user\'s plan', function () {
    $mine = User::factory()->create();
    $other = PaydayPlan::factory()->create(['payday_day' => 9]);

    app(UpdatePaydayPlanAction::class)->execute($mine->id, [
        'payday_day' => 3, 'declared_salary' => '3000', 'savings_percent' => 10, 'is_active' => true,
    ]);

    expect($other->fresh()->payday_day)->toBe(9);
});
