<?php

declare(strict_types=1);

/**
 * The half of `ImportController` nothing covered.
 *
 * `ImportPdfTest` covers uploading, listing and downloading; `CrossUserAccessTest`
 * covers whether a caller can reach another user's rows. Update, delete and the two
 * reference endpoints had nothing at all — and the reference endpoints are what the
 * upload form is built from, so an empty response there is a form that cannot be
 * submitted.
 */

use App\Enums\ImportStatus;
use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\PaymentService;
use App\Models\User;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function importOwner(): array
{
    /** @var \Tests\TestCase $t */
    return test()->userWithAuth();
}

function importFor(User $user, ?FinancialEntity $entity = null): Import
{
    return Import::factory()->create([
        'user_id' => $user->id,
        'financial_entity_id' => ($entity ?? FinancialEntity::factory()->create())->id,
        'status' => ImportStatus::PENDING,
    ]);
}

// ------------------------------------------------------------------ reference

it('lista las entidades financieras que el formulario de carga necesita', function () {
    [, $headers] = importOwner();
    FinancialEntity::factory()->create(['name' => 'Banco de Crédito del Perú']);

    $response = $this->getJson('/api/get-bank', $headers);

    $response->assertOk();
    expect(collect($response->json())->pluck('name'))->toContain('Banco de Crédito del Perú');
});

it('lista los servicios de pago', function () {
    [, $headers] = importOwner();
    $entity = FinancialEntity::factory()->create();
    PaymentService::query()->create([
        'name' => 'Yape',
        'financial_entity_id' => $entity->id,
        'type' => 'Billetera Digital',
    ]);

    $response = $this->getJson('/api/get-service', $headers);

    $response->assertOk();
    expect(collect($response->json())->pluck('name'))->toContain('Yape');
});

it('rechaza los endpoints de referencia sin autenticacion', function (string $route) {
    $this->getJson($route)->assertStatus(401);
})->with(['/api/get-bank', '/api/get-service']);

// --------------------------------------------------------------------- update

it('actualiza un import propio', function () {
    [$user, $headers] = importOwner();
    $import = importFor($user);

    $this->putJson("/api/imports/{$import->id}", ['name' => 'renombrado.pdf'], $headers)
        ->assertOk();

    expect($import->refresh()->name)->toBe('renombrado.pdf');
});

it('responde 404 al actualizar el import de otro usuario', function () {
    [, $headers] = importOwner();
    $stranger = User::factory()->create();
    $foreign = importFor($stranger);

    $this->putJson("/api/imports/{$foreign->id}", ['name' => 'secuestrado.pdf'], $headers)
        ->assertStatus(404);

    expect($foreign->refresh()->name)->not->toBe('secuestrado.pdf');
});

// -------------------------------------------------------------------- destroy

it('borra un import propio', function () {
    [$user, $headers] = importOwner();
    $import = importFor($user);

    $this->deleteJson("/api/imports/{$import->id}", [], $headers)->assertOk();

    expect(Import::query()->whereKey($import->id)->exists())->toBeFalse();
});

it('responde 404 al borrar el import de otro usuario y no lo toca', function () {
    [, $headers] = importOwner();
    $stranger = User::factory()->create();
    $foreign = importFor($stranger);

    $this->deleteJson("/api/imports/{$foreign->id}", [], $headers)->assertStatus(404);

    expect(Import::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

// ----------------------------------------------------------------------- list

it('ordena el listado con el import mas reciente primero', function () {
    [$user, $headers] = importOwner();

    $older = importFor($user);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = importFor($user);
    $newer->forceFill(['created_at' => now()])->save();

    $ids = collect($this->getJson('/api/imports', $headers)->json('data'))->pluck('id');

    expect($ids->first())->toBe($newer->id);
});

it('no lista los imports de otro usuario', function () {
    [$user, $headers] = importOwner();
    $stranger = User::factory()->create();
    importFor($user);
    $foreign = importFor($stranger);

    $ids = collect($this->getJson('/api/imports', $headers)->json('data'))->pluck('id');

    expect($ids)->not->toContain($foreign->id);
});
