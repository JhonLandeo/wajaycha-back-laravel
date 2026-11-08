<?php

declare(strict_types=1);

use App\Models\IncomeSource;
use App\Models\IncomeSourceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sourcePayload(array $overrides = []): array
{
    return [
        'name' => 'Sueldo',
        'kind' => 'salary',
        'amount' => 3416,
        'effective_from' => '2026-01',
        ...$overrides,
    ];
}

it('rejects unauthenticated requests', function () {
    $this->getJson('/api/income-sources')->assertStatus(401);
    $this->postJson('/api/income-sources', sourcePayload())->assertStatus(401);
});

it('creates a source with its first monthly version', function () {
    [$user, $headers] = $this->userWithAuth();

    $this->postJson('/api/income-sources', sourcePayload(), $headers)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Sueldo')
        ->assertJsonPath('data.kind', 'salary')
        ->assertJsonPath('data.versions.0.amount', '3416.00')
        ->assertJsonPath('data.versions.0.effective_from', '2026-01-01')
        ->assertJsonPath('data.versions.0.effective_to', null);

    expect(IncomeSource::where('user_id', $user->id)->count())->toBe(1);
});

it('lists only the authenticated user sources', function () {
    [$owner, $headers] = $this->userWithAuth();
    [$other] = $this->userWithAuth();

    IncomeSource::create(['user_id' => $owner->id, 'name' => 'Sueldo', 'kind' => 'salary']);
    IncomeSource::create(['user_id' => $other->id, 'name' => 'Negocio', 'kind' => 'business']);

    $this->getJson('/api/income-sources', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Sueldo');
});

it('adds a new version and closes the previous one the day before', function () {
    [$user, $headers] = $this->userWithAuth();
    $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Sueldo', 'kind' => 'salary']);
    IncomeSourceVersion::create([
        'income_source_id' => $source->id,
        'amount' => '3000.00',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $this->postJson("/api/income-sources/{$source->id}/versions", [
        'amount' => 4000,
        'effective_from' => '2026-06',
    ], $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data.versions')
        ->assertJsonPath('data.versions.0.effective_to', '2026-05-31')
        ->assertJsonPath('data.versions.1.amount', '4000.00')
        ->assertJsonPath('data.versions.1.effective_from', '2026-06-01')
        ->assertJsonPath('data.versions.1.effective_to', null);
});

it('rejects a version that does not move forward', function () {
    [$user, $headers] = $this->userWithAuth();
    $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Sueldo', 'kind' => 'salary']);
    IncomeSourceVersion::create([
        'income_source_id' => $source->id,
        'amount' => '3000.00',
        'effective_from' => '2026-06-01',
        'effective_to' => null,
    ]);

    $this->postJson("/api/income-sources/{$source->id}/versions", [
        'amount' => 4000,
        'effective_from' => '2026-05',
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('effective_from');
});

it('closes the open version on the last day of the chosen month', function () {
    [$user, $headers] = $this->userWithAuth();
    $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Sueldo', 'kind' => 'salary']);
    IncomeSourceVersion::create([
        'income_source_id' => $source->id,
        'amount' => '3000.00',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $this->postJson("/api/income-sources/{$source->id}/close", [
        'effective_to' => '2026-11',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.versions.0.effective_to', '2026-11-30');

    $this->postJson("/api/income-sources/{$source->id}/close", [
        'effective_to' => '2026-12',
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('effective_to');
});

it('lets a new version start after a source was closed', function () {
    [$user, $headers] = $this->userWithAuth();
    $source = IncomeSource::create(['user_id' => $user->id, 'name' => 'Negocio', 'kind' => 'business']);
    IncomeSourceVersion::create([
        'income_source_id' => $source->id,
        'amount' => '1500.00',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-05-31',
    ]);

    $this->postJson("/api/income-sources/{$source->id}/versions", [
        'amount' => 2000,
        'effective_from' => '2026-06',
    ], $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data.versions');
});

it('does not expose another user source', function () {
    [$owner] = $this->userWithAuth();
    [, $headers] = $this->userWithAuth();

    $source = IncomeSource::create(['user_id' => $owner->id, 'name' => 'Sueldo', 'kind' => 'salary']);

    $this->putJson("/api/income-sources/{$source->id}", [
        'name' => 'Ajeno',
        'kind' => 'other',
    ], $headers)->assertStatus(404);

    $this->postJson("/api/income-sources/{$source->id}/versions", [
        'amount' => 1,
        'effective_from' => '2026-01',
    ], $headers)->assertStatus(404);

    $this->deleteJson("/api/income-sources/{$source->id}", [], $headers)->assertStatus(404);
});

it('validates the source fields', function (array $payload, string $field) {
    [, $headers] = $this->userWithAuth();

    $this->postJson('/api/income-sources', sourcePayload($payload), $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'negative amount' => [['amount' => -1], 'amount'],
    'zero amount' => [['amount' => 0], 'amount'],
    'three decimals' => [['amount' => 10.123], 'amount'],
    'unknown kind' => [['kind' => 'lottery'], 'kind'],
    'bad month' => [['effective_from' => '2026-13'], 'effective_from'],
]);

it('rejects a duplicate name for the same user', function () {
    [$user, $headers] = $this->userWithAuth();

    IncomeSource::create(['user_id' => $user->id, 'name' => 'Sueldo', 'kind' => 'salary']);

    $this->postJson('/api/income-sources', sourcePayload(), $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});
