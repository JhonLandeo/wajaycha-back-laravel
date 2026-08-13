<?php

declare(strict_types=1);

use App\Enums\ReconciliationStatus;
use App\Enums\SourceType;
use App\Models\ReconciliationCandidate;
use App\Models\User;
use App\Services\Reconciliation\DuplicateCandidateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Arma el caso completo — captura, import y la sospecha entre ambos — y devuelve
 * al usuario junto con el candidato pendiente.
 *
 * @return array{0: User, 1: ReconciliationCandidate}
 */
function pendingSuspicion(): array
{
    $user = User::factory()->create();

    // Diez horas de separacion: fuera de la ventana automatica, dentro de la que
    // se pregunta. Un par mas ajustado lo unificaria el sistema y no habria nada
    // pendiente que listar.
    movement($user, SourceType::CAPTURE, '25.50', '2026-08-10 02:55:00', 'bodega de la esquina');
    $importada = movement($user, SourceType::IMPORT_APP, '25.50', '2026-08-10 12:56:00', 'JUAN PEREZ RODRIGUEZ');

    return [$user, app(DuplicateCandidateDetector::class)->inspect($importada)];
}

/**
 * Un par que el sistema unifico solo, listo para auditar.
 *
 * @return array{0: User, 1: ReconciliationCandidate}
 */
function autoMergedPair(): array
{
    $user = User::factory()->create();

    movement($user, SourceType::CAPTURE, '40.00', '2026-08-10 14:30:00', 'pollería del jirón');
    $importada = movement($user, SourceType::IMPORT_APP, '40.00', '2026-08-10 14:30:06', 'ROSA QUISPE MAMANI');

    return [$user, app(DuplicateCandidateDetector::class)->inspect($importada)];
}

it('lista las sospechas pendientes con los dos lados a la vista', function () {
    [$user] = pendingSuspicion();

    $response = $this->getJson('/api/reconciliation-candidates', $this->actingAsJwtUser($user));

    $response->assertOk()->assertJsonCount(1, 'data');

    // El usuario solo puede decidir si ve ambas descripciones: son justamente las que
    // no coinciden, y por eso ninguna comparacion de texto resolvio esto sola.
    expect($response->json('data.0.transaction.description'))->toBe('JUAN PEREZ RODRIGUEZ')
        ->and($response->json('data.0.candidate.description'))->toBe('bodega de la esquina')
        ->and($response->json('data.0.transaction.amount'))->toBe('25.50');
});

it('deja de listar la sospecha una vez resuelta', function () {
    [$user, $candidate] = pendingSuspicion();

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/confirm", [], $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonPath('data.status', ReconciliationStatus::CONFIRMED->value);

    $this->getJson('/api/reconciliation-candidates', $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('descartar deja los dos movimientos contando', function () {
    [$user, $candidate] = pendingSuspicion();

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/reject", [], $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonPath('data.status', ReconciliationStatus::REJECTED->value);

    $resuelto = $candidate->fresh();

    expect($resuelto->transaction->matched_transaction_id)->toBeNull()
        ->and($resuelto->candidateTransaction->matched_transaction_id)->toBeNull();
});

it('no deja resolver la sospecha de otro usuario', function () {
    [, $candidate] = pendingSuspicion();
    $intruso = User::factory()->create();

    // 404 y no 403: la segunda respuesta confirmaria que la sospecha existe.
    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/confirm", [], $this->actingAsJwtUser($intruso))
        ->assertNotFound();

    expect($candidate->fresh()->status)->toBe(ReconciliationStatus::PENDING);
});

it('no expone las sospechas de otro usuario en el listado', function () {
    pendingSuspicion();
    $intruso = User::factory()->create();

    $this->getJson('/api/reconciliation-candidates', $this->actingAsJwtUser($intruso))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('exige autenticacion', function () {
    $this->getJson('/api/reconciliation-candidates')->assertUnauthorized();
    $this->getJson('/api/reconciliation-candidates/auto-merged')->assertUnauthorized();
});

it('muestra lo que el sistema unifico por su cuenta', function () {
    [$user] = autoMergedPair();

    // Sin esta lista, decidir solo seria decidir en silencio. Es la contrapartida
    // exacta de que el sistema no pregunte.
    $response = $this->getJson('/api/reconciliation-candidates/auto-merged', $this->actingAsJwtUser($user));

    $response->assertOk()->assertJsonCount(1, 'data');

    expect($response->json('data.0.candidate.description'))->toBe('pollería del jirón')
        ->and($response->json('data.0.transaction.description'))->toBe('ROSA QUISPE MAMANI')
        ->and($response->json('data.0.resolved_at'))->not->toBeNull();
});

it('no lista lo unificado por el sistema como si estuviera pendiente', function () {
    [$user] = autoMergedPair();

    $this->getJson('/api/reconciliation-candidates', $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('deshace una unificacion automatica y la saca de la lista', function () {
    [$user, $candidate] = autoMergedPair();

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/undo", [], $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonPath('data.status', ReconciliationStatus::REJECTED->value);

    $resuelto = $candidate->fresh();

    expect($resuelto->transaction->matched_transaction_id)->toBeNull()
        ->and($resuelto->candidateTransaction->matched_transaction_id)->toBeNull();

    $this->getJson('/api/reconciliation-candidates/auto-merged', $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('no expone ni deja deshacer lo unificado de otro usuario', function () {
    [, $candidate] = autoMergedPair();
    $intruso = User::factory()->create();

    $this->getJson('/api/reconciliation-candidates/auto-merged', $this->actingAsJwtUser($intruso))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/undo", [], $this->actingAsJwtUser($intruso))
        ->assertNotFound();

    // El satelite es la captura, el lado de menor autoridad: el par sigue unido.
    expect($candidate->fresh()->candidateTransaction->matched_transaction_id)->not->toBeNull();
});

it('responde 409 al intentar deshacer una decision propia', function () {
    [$user, $candidate] = pendingSuspicion();

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/confirm", [], $this->actingAsJwtUser($user))
        ->assertOk();

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/undo", [], $this->actingAsJwtUser($user))
        ->assertStatus(409);
});

it('responde 409 cuando la sospecha ya fue resuelta en otra pestaña', function () {
    [$user, $candidate] = pendingSuspicion();

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/reject", [], $this->actingAsJwtUser($user))
        ->assertOk();

    $this->postJson("/api/reconciliation-candidates/{$candidate->id}/confirm", [], $this->actingAsJwtUser($user))
        ->assertStatus(409);

    expect(ReconciliationCandidate::sole()->status)->toBe(ReconciliationStatus::REJECTED);
});
