<?php

declare(strict_types=1);

use App\Models\IncomeSource;
use App\Models\IncomeSourceVersion;
use App\Models\User;
use App\Services\Income\PlannedIncomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, array{amount: string, from: string, to: string|null}>  $versions
 */
function incomeSourceWith(User $user, array $versions, string $name = 'Sueldo', string $kind = 'salary'): IncomeSource
{
    $source = IncomeSource::create([
        'user_id' => $user->id,
        'name' => $name,
        'kind' => $kind,
    ]);

    foreach ($versions as $version) {
        IncomeSourceVersion::create([
            'income_source_id' => $source->id,
            'amount' => $version['amount'],
            'effective_from' => $version['from'],
            'effective_to' => $version['to'],
        ]);
    }

    return $source;
}

it('returns null when the user has no sources', function () {
    $user = User::factory()->create();

    expect(app(PlannedIncomeService::class)->forWindow($user->id, 11, 2026, 1))->toBeNull();
});

it('uses the plan of the selected month', function () {
    $user = User::factory()->create();
    incomeSourceWith($user, [['amount' => '3416.00', 'from' => '2026-01-01', 'to' => null]]);

    $service = app(PlannedIncomeService::class);

    expect($service->forWindow($user->id, 11, 2026, 1))->toEqual(3416.0);
});

it('sums every month of the window when no month is selected', function () {
    $user = User::factory()->create();
    incomeSourceWith($user, [['amount' => '3416.00', 'from' => '2026-01-01', 'to' => null]]);

    $service = app(PlannedIncomeService::class);

    expect($service->forWindow($user->id, null, 2026, 12))->toEqual(40992.0)
        ->and($service->forWindow($user->id, null, 2026, 11))->toEqual(37576.0);
});

it('applies a raise only from its effective month', function () {
    $user = User::factory()->create();
    incomeSourceWith($user, [
        ['amount' => '3000.00', 'from' => '2026-01-01', 'to' => '2026-05-31'],
        ['amount' => '4000.00', 'from' => '2026-06-01', 'to' => null],
    ]);

    $service = app(PlannedIncomeService::class);

    expect($service->forWindow($user->id, 5, 2026, 1))->toEqual(3000.0)
        ->and($service->forWindow($user->id, 6, 2026, 1))->toEqual(4000.0)
        ->and($service->forWindow($user->id, null, 2026, 12))->toEqual(43000.0);
});

it('adds the amounts of several sources', function () {
    $user = User::factory()->create();
    incomeSourceWith($user, [['amount' => '3000.00', 'from' => '2026-01-01', 'to' => null]], 'Sueldo');
    incomeSourceWith($user, [['amount' => '500.00', 'from' => '2026-01-01', 'to' => null]], 'Negocio', 'business');

    expect(app(PlannedIncomeService::class)->forWindow($user->id, 11, 2026, 1))->toEqual(3500.0);
});

it('counts zero when the source closed before the requested month', function () {
    $user = User::factory()->create();
    incomeSourceWith($user, [['amount' => '3000.00', 'from' => '2026-01-01', 'to' => '2026-05-31']]);

    expect(app(PlannedIncomeService::class)->forWindow($user->id, 6, 2026, 1))->toEqual(0.0);
});

it('returns null for all history without a year', function () {
    $user = User::factory()->create();
    incomeSourceWith($user, [['amount' => '3000.00', 'from' => '2026-01-01', 'to' => null]]);

    expect(app(PlannedIncomeService::class)->forWindow($user->id, 11, null, 1))->toBeNull();
});
