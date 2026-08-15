<?php

declare(strict_types=1);

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Regression guard for an endpoint that accepted unlimited password guesses.
 *
 * `LoginRequest` already contained the whole throttle sequence and the
 * controller never called it, so every counter in this file was dead: no
 * attempt was ever recorded and no lockout was ever evaluated. Nothing failed,
 * because nothing asserted it. These tests exist so the wiring cannot be
 * quietly removed again — a rate limiter that is not exercised by a test is
 * indistinguishable from one that is not called.
 *
 * Two independent layers are covered, and they defend against different
 * attacks:
 *
 * - per (email, IP), in `LoginRequest` — many passwords against one account
 * - per IP, via `throttle:` on the route — one password against many accounts
 *
 * A test for only one of them would pass while the other attack still works.
 */
it('bloquea el intento que sigue a los cinco fallos permitidos', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'ada@example.test',
            'password' => 'contrasena-incorrecta',
        ])->assertStatus(401);
    }

    $this->postJson('/api/login', [
        'email' => 'ada@example.test',
        'password' => 'contrasena-incorrecta',
    ])->assertStatus(429);
});

it('bloquea tambien la contrasena correcta mientras dura el bloqueo', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'ada@example.test',
            'password' => 'contrasena-incorrecta',
        ])->assertStatus(401);
    }

    // Si adivinar la contrasena al sexto intento devolviera un token, el limite
    // no seria un limite: seria un retardo.
    $this->postJson('/api/login', [
        'email' => 'ada@example.test',
        'password' => 'password',
    ])->assertStatus(429);
});

it('dice cuanto falta para poder reintentar', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'ada@example.test',
            'password' => 'contrasena-incorrecta',
        ]);
    }

    $response = $this->postJson('/api/login', [
        'email' => 'ada@example.test',
        'password' => 'contrasena-incorrecta',
    ]);

    $response->assertStatus(429)
        ->assertJsonStructure(['error', 'message', 'retry_after'])
        ->assertHeader('Retry-After');

    expect($response->json('retry_after'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(LoginRequest::DECAY_SECONDS);
});

it('emite el evento Lockout cuando corta un intento', function () {
    Event::fake([Lockout::class]);

    User::factory()->create(['email' => 'ada@example.test']);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS + 1) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'ada@example.test',
            'password' => 'contrasena-incorrecta',
        ]);
    }

    Event::assertDispatched(Lockout::class);
});

it('no arrastra el bloqueo de una cuenta a otra', function () {
    User::factory()->create(['email' => 'ada@example.test']);
    User::factory()->create(['email' => 'grace@example.test']);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'ada@example.test',
            'password' => 'contrasena-incorrecta',
        ]);
    }

    // Un atacante que quema los intentos de una direccion no puede dejar fuera
    // de su propia cuenta a otro usuario.
    $this->postJson('/api/login', [
        'email' => 'grace@example.test',
        'password' => 'password',
    ])->assertOk();
});

it('un login exitoso limpia los fallos acumulados', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    foreach (range(1, 4) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'ada@example.test',
            'password' => 'contrasena-incorrecta',
        ])->assertStatus(401);
    }

    $this->postJson('/api/login', [
        'email' => 'ada@example.test',
        'password' => 'password',
    ])->assertOk();

    // Sin la limpieza estos dos sumarian seis y el segundo devolveria 429.
    foreach (range(1, 2) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'ada@example.test',
            'password' => 'contrasena-incorrecta',
        ])->assertStatus(401);
    }
});

it('corta por IP a quien prueba una contrasena contra muchas direcciones', function () {
    // El contador de LoginRequest lleva el email en su clave, asi que cada
    // direccion nueva le abre un contador limpio y nunca lo activa. Esto es lo
    // unico que detiene el rociado de contrasenas.
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/login', [
            'email' => "victima{$i}@example.test",
            'password' => 'Password123',
        ])->assertStatus(401);
    }

    $this->postJson('/api/login', [
        'email' => 'victima11@example.test',
        'password' => 'Password123',
    ])->assertStatus(429);
});

it('corta por IP el registro masivo de cuentas', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/register', [
            'name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => "ada{$i}@example.test",
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertOk();
    }

    $this->postJson('/api/register', [
        'name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada6@example.test',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertStatus(429);
});
