<?php

declare(strict_types=1);

use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    $this->travelBack();
});

function recurringMerchant(int $userId, string $entity): Detail
{
    return Detail::factory()->create([
        'user_id' => $userId,
        'description' => $entity,
        'entity_clean' => $entity,
    ]);
}

/**
 * @param  array<int, string>  $dates
 * @param  array<string, mixed>  $overrides
 */
function seedCharges(User $user, Detail $detail, float $amount, array $dates, array $overrides = []): void
{
    foreach ($dates as $date) {
        Transaction::factory()->expense()->create([
            'user_id' => $user->id,
            'detail_id' => $detail->id,
            'amount' => $amount,
            'date_operation' => $date.' 10:00:00',
            ...$overrides,
        ]);
    }
}

it('rechaza la lectura sin autenticacion', function () {
    $this->getJson('/api/recurring')->assertStatus(401);
});

it('detecta una suscripcion mensual y la anualiza', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $detail = recurringMerchant($user->id, 'NETFLIX');

    seedCharges($user, $detail, 25.90, [
        '2026-08-15',
        '2026-09-14',
        '2026-10-14',
        '2026-11-13',
    ]);

    $this->getJson('/api/recurring', $this->jwtHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.window_months', 24)
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.monthly_total', 25.90)
        ->assertJsonPath('data.annual_total', 310.80)
        ->assertJsonPath('data.charges.0.merchant', 'NETFLIX')
        ->assertJsonPath('data.charges.0.cadence', 'monthly')
        ->assertJsonPath('data.charges.0.cadence_label', 'Mensual')
        ->assertJsonPath('data.charges.0.occurrences', 4)
        ->assertJsonPath('data.charges.0.amount', 25.90)
        ->assertJsonPath('data.charges.0.last_charged_on', '2026-11-13')
        ->assertJsonPath('data.charges.0.next_expected_on', '2026-12-13');
});

it('solo lee los movimientos del usuario autenticado', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00'));

    $owner = User::factory()->create();
    $other = User::factory()->create();

    seedCharges($owner, recurringMerchant($owner->id, 'NETFLIX'), 25.90, [
        '2026-09-01', '2026-10-01', '2026-11-01',
    ]);

    seedCharges($other, recurringMerchant($other->id, 'SPOTIFY'), 18.00, [
        '2026-09-01', '2026-10-01', '2026-11-01',
    ]);

    $this->getJson('/api/recurring', $this->jwtHeaders($owner))
        ->assertOk()
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.charges.0.merchant', 'NETFLIX');
});

it('no confunde el gasto variable semanal con una suscripcion', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $detail = recurringMerchant($user->id, 'WONG');

    foreach ([120.0, 300.0, 80.0, 250.0] as $index => $amount) {
        Transaction::factory()->expense()->create([
            'user_id' => $user->id,
            'detail_id' => $detail->id,
            'amount' => $amount,
            'date_operation' => CarbonImmutable::parse('2026-10-01')->addWeeks($index)->toDateString().' 10:00:00',
        ]);
    }

    $this->getJson('/api/recurring', $this->jwtHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.count', 0)
        ->assertJsonPath('data.charges', []);
});

it('no cuenta ingresos', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $detail = recurringMerchant($user->id, 'SUELDO');

    foreach (['2026-09-01', '2026-10-01', '2026-11-01'] as $date) {
        Transaction::factory()->income()->create([
            'user_id' => $user->id,
            'detail_id' => $detail->id,
            'amount' => 3500.00,
            'date_operation' => $date.' 10:00:00',
        ]);
    }

    $this->getJson('/api/recurring', $this->jwtHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});

it('no cuenta el duplicado que la conciliacion ya unifico', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00'));

    $user = User::factory()->create();
    $detail = recurringMerchant($user->id, 'SPOTIFY');

    $kept = Transaction::factory()->expense()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'amount' => 18.00,
        'date_operation' => '2026-09-01 10:00:00',
    ]);

    seedCharges($user, $detail, 18.00, [
        '2026-09-01',
        '2026-10-01',
        '2026-11-01',
    ], ['matched_transaction_id' => $kept->id]);

    // El único cobro que la vista conserva es el que no está marcado como
    // duplicado, y uno solo no alcanza para ninguna cadencia.
    $this->getJson('/api/recurring', $this->jwtHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});
