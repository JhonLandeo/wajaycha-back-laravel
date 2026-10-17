<?php

declare(strict_types=1);

use App\Models\PaydayPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function validPlan(array $override = []): array
{
    return ['payday_day' => 3, 'declared_salary' => 3000, 'savings_percent' => 10, 'is_active' => true, ...$override];
}

it('rejects unauthenticated requests with 401', function () {
    $this->getJson('/api/payday-plan')->assertStatus(401);
    $this->putJson('/api/payday-plan', validPlan())->assertStatus(401);
});

it('returns an empty state with no invented defaults when there is no plan', function () {
    [, $headers] = $this->userWithAuth();

    $this->getJson('/api/payday-plan', $headers)
        ->assertOk()
        ->assertExactJson(['data' => null]);
});

it('creates the plan and reads it back with the computed savings amount', function () {
    [$user, $headers] = $this->userWithAuth();

    $expected = [
        'data' => [
            'payday_day' => 3,
            'declared_salary' => '3000.00',
            'savings_percent' => 10,
            'is_active' => true,
            'savings_amount' => '300.00',
        ],
    ];

    $this->putJson('/api/payday-plan', validPlan(), $headers)->assertOk()->assertExactJson($expected);
    $this->getJson('/api/payday-plan', $headers)->assertOk()->assertExactJson($expected);

    expect(PaydayPlan::where('user_id', $user->id)->count())->toBe(1);
});

it('replaces the plan on a second save instead of adding another', function () {
    [$user, $headers] = $this->userWithAuth();

    $this->putJson('/api/payday-plan', validPlan(), $headers)->assertOk();
    $this->putJson('/api/payday-plan', validPlan(['payday_day' => 28, 'savings_percent' => 15, 'declared_salary' => 2500.5, 'is_active' => false]), $headers)
        ->assertOk()
        ->assertJsonPath('data.savings_amount', '375.08')
        ->assertJsonPath('data.is_active', false);

    expect(PaydayPlan::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects invalid values with field errors and persists nothing', function (array $override, string $field) {
    [$user, $headers] = $this->userWithAuth();

    $this->putJson('/api/payday-plan', validPlan($override), $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(PaydayPlan::where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    'day 31' => [['payday_day' => 31], 'payday_day'],
    'day 29' => [['payday_day' => 29], 'payday_day'],
    'day 0' => [['payday_day' => 0], 'payday_day'],
    'day text' => [['payday_day' => 'tres'], 'payday_day'],
    'salary zero' => [['declared_salary' => 0], 'declared_salary'],
    'salary negative' => [['declared_salary' => -5], 'declared_salary'],
    'salary text' => [['declared_salary' => 'mucho'], 'declared_salary'],
    'salary three decimals' => [['declared_salary' => 10.123], 'declared_salary'],
    'salary too big' => [['declared_salary' => 1000000000000000], 'declared_salary'],
    'percent 0' => [['savings_percent' => 0], 'savings_percent'],
    'percent 101' => [['savings_percent' => 101], 'savings_percent'],
    'active missing' => [['is_active' => null], 'is_active'],
]);

it('keeps the stored plan unchanged when an update is rejected', function () {
    [, $headers] = $this->userWithAuth();
    $this->putJson('/api/payday-plan', validPlan(), $headers)->assertOk();

    $this->putJson('/api/payday-plan', validPlan(['payday_day' => 31]), $headers)->assertStatus(422);

    $this->getJson('/api/payday-plan', $headers)->assertJsonPath('data.payday_day', 3);
});

it('requires every field', function () {
    [, $headers] = $this->userWithAuth();

    $this->putJson('/api/payday-plan', [], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payday_day', 'declared_salary', 'savings_percent', 'is_active']);
});

it('never reads or writes another user\'s plan, even if a user id is sent', function () {
    $other = User::factory()->create();
    PaydayPlan::factory()->create(['user_id' => $other->id, 'payday_day' => 9]);
    [$user, $headers] = $this->userWithAuth();

    $this->getJson('/api/payday-plan', $headers)->assertOk()->assertExactJson(['data' => null]);

    $this->putJson('/api/payday-plan', validPlan(['user_id' => $other->id]), $headers)->assertOk();

    expect(PaydayPlan::where('user_id', $user->id)->count())->toBe(1)
        ->and(PaydayPlan::where('user_id', $other->id)->sole()->payday_day)->toBe(9);
});
