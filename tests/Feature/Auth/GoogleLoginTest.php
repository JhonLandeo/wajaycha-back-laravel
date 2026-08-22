<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleIdTokenFactory;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

/**
 * `POST /api/auth/google` — the second way into this application.
 *
 * The login screen carried four social buttons for a long time and not one of
 * them had a click handler: they were `<button>` elements with a coloured border
 * and an icon, promising a door that did not exist. This file is what makes one
 * of them true, and it leans on real cryptography rather than a mocked verifier —
 * see {@see GoogleIdTokenFactory} for why that distinction is the whole point.
 *
 * Every rejection below is a way in that must stay closed. They are cheap to
 * write and expensive to discover in production.
 */
beforeEach(function (): void {
    config()->set('services.google.client_id', GoogleIdTokenFactory::CLIENT_ID);
});

/**
 * Stands in for Google's key endpoint.
 *
 * Called per test rather than once in `beforeEach` because `Http::fake()` MERGES
 * stubs instead of replacing them, and the first registered match wins. A
 * healthy stub set up globally would quietly outrank the 500 the outage test
 * registers later — and that test would pass for the wrong reason, which is the
 * one thing a test must never do.
 */
function fakeGoogleCerts(): void
{
    Http::fake([
        'https://www.googleapis.com/oauth2/v3/certs' => Http::response(GoogleIdTokenFactory::jwks()),
    ]);
}

it('crea la cuenta y su workspace por defecto en el primer ingreso', function () {
    fakeGoogleCerts();

    $response = $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ]);

    $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

    $user = User::query()->where('email', 'ana@example.test')->firstOrFail();

    expect($user->name)->toBe('Ana')
        ->and($user->last_name)->toBe('Quispe')
        ->and($user->google_id)->toBe('104729183746152938471')
        // Sin contraseña, y sin una inventada: ver la migración.
        ->and($user->password)->toBeNull()
        // Google ya verificó el email; dejarlo null dejaría la cuenta sin verificar
        // para siempre frente a MustVerifyEmail.
        ->and($user->email_verified_at)->not->toBeNull();

    // El observer de User es el que siembra el workspace. Si esta cuenta se
    // creara por fuera del modelo, entraría a un producto vacío: sin categorías
    // no hay nada que reportar y Pareto no tiene contra qué bandear.
    expect(Category::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);
});

it('devuelve un token que autentica de verdad contra la API', function () {
    fakeGoogleCerts();

    $response = $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ]);

    $token = $response->json('token');
    $user = User::query()->where('email', 'ana@example.test')->firstOrFail();

    expect(JWTAuth::setToken($token)->authenticate()?->getKey())->toBe($user->getKey());
});

it('enlaza la cuenta que ya existia con contraseña en vez de duplicarla', function () {
    fakeGoogleCerts();

    $existing = User::factory()->create([
        'email' => 'ana@example.test',
        'name' => 'Ana Maria',
    ]);

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertOk();

    $existing->refresh();

    expect(User::query()->count())->toBe(1)
        ->and($existing->google_id)->toBe('104729183746152938471')
        // El nombre que el usuario eligió gana sobre el que manda Google: enlazar
        // es sumar una forma de entrar, no reescribirle el perfil.
        ->and($existing->name)->toBe('Ana Maria')
        // Y la contraseña sigue ahí: ahora tiene DOS puertas, no una distinta.
        ->and($existing->password)->not->toBeNull();
});

it('no crea una segunda cuenta al volver a entrar con Google', function () {
    fakeGoogleCerts();

    $credential = GoogleIdTokenFactory::token();

    $this->postJson('/api/auth/google', ['credential' => $credential])->assertOk();
    $this->postJson('/api/auth/google', ['credential' => $credential])->assertOk();

    expect(User::query()->count())->toBe(1);
});

it('sigue enlazando aunque Google cambie el email de esa cuenta', function () {
    fakeGoogleCerts();

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertOk();

    // Mismo `sub`, email nuevo. Por esto el vínculo se persiste por `sub` y no
    // por email: la persona es la misma y no puede quedar afuera de su propia
    // cuenta porque cambió de dirección.
    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(['email' => 'ana.nueva@example.test']),
    ])->assertOk();

    expect(User::query()->count())->toBe(1);
});

it('rechaza un token que Google no firmo', function () {
    fakeGoogleCerts();

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::forgedToken(),
    ])->assertStatus(401);

    expect(User::query()->count())->toBe(0);
});

it('rechaza un token emitido para otra aplicacion', function () {
    fakeGoogleCerts();

    // La firma es válida — es un token real de Google. Lo que no es válido es
    // que sea PARA nosotros. Sin chequear `aud`, cualquier sitio con un cliente
    // de Google podría abrir cuentas acá con los tokens de sus propios usuarios.
    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(['aud' => 'otra-app.apps.googleusercontent.com']),
    ])->assertStatus(401);

    expect(User::query()->count())->toBe(0);
});

it('rechaza un token vencido', function () {
    fakeGoogleCerts();

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token([
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]),
    ])->assertStatus(401);
});

it('rechaza un emisor que no es Google', function () {
    fakeGoogleCerts();

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(['iss' => 'https://accounts.evil.test']),
    ])->assertStatus(401);
});

it('rechaza una cuenta cuyo email Google no verifico', function () {
    fakeGoogleCerts();

    // Este es el chequeo del que depende TODO el enlace por email. Sin él,
    // cualquiera que registre una cuenta de Google declarando el email de otro
    // se lleva las finanzas de esa persona.
    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(['email_verified' => false]),
    ])->assertStatus(401);

    expect(User::query()->count())->toBe(0);
});

it('rechaza el email de un tercero contra una cuenta ya enlazada a otro Google', function () {
    fakeGoogleCerts();

    User::factory()->create([
        'email' => 'ana@example.test',
        'google_id' => 'otro-sub-de-google',
    ]);

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertStatus(409);
});

it('rechaza una credencial que no es un JWT', function () {
    fakeGoogleCerts();

    $this->postJson('/api/auth/google', ['credential' => 'esto-no-es-un-token'])
        ->assertStatus(401);
});

it('exige la credencial', function () {
    fakeGoogleCerts();

    $this->postJson('/api/auth/google', [])->assertStatus(422);
});

it('avisa que el servicio no esta disponible si falta el client id', function () {
    fakeGoogleCerts();

    // Un deploy sin GOOGLE_CLIENT_ID no es un intento inválido. Contestar 401 le
    // diría al usuario que su cuenta está mal cuando el que está mal es el server.
    config()->set('services.google.client_id', null);

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertStatus(503);
});

it('avisa que el servicio no esta disponible si Google no entrega sus claves', function () {
    // Sin `fakeGoogleCerts()` a proposito: es el unico test que quiere a Google caido.
    Http::fake([
        'https://www.googleapis.com/oauth2/v3/certs' => Http::response('', 500),
    ]);

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertStatus(503);
});

it('no le cobra al usuario un error de programacion del servidor', function () {
    // Pasó de verdad el 2026-08-22: `firebase/php-jwt` no estaba instalado en un
    // contenedor y el verificador lanzó `Class "Firebase\\JWT\\JWT" not found`.
    // Un `catch (Throwable)` se lo comió y contestó 401 "no pudimos validar tu
    // cuenta de Google" — o sea, le echó la culpa a la cuenta de quien entraba.
    // En produccion esa persona reintenta para siempre contra un servidor roto y
    // nadie mira nunca un log, porque un 401 no despierta a ninguna alerta.
    //
    // Un `Error` es un bug nuestro. Tiene que salir como 500, llegar a Sentry, y
    // no disfrazarse de credencial invalida.
    Http::fake([
        'https://www.googleapis.com/oauth2/v3/certs' => function (): never {
            throw new Error('Class "Firebase\\JWT\\JWT" not found');
        },
    ]);

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertStatus(500);
});

it('no expone el identificador de Google en la respuesta', function () {
    fakeGoogleCerts();

    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertOk()->assertJsonMissingPath('user.google_id');
});

it('no deja entrar con contraseña a una cuenta creada por Google', function () {
    fakeGoogleCerts();

    // `password` quedó en null. Laravel corta en dos lugares antes del hasher
    // (EloquentUserProvider::validateCredentials), pero eso es comportamiento de
    // un paquete: si mañana cambia, este test avisa y no lo hace un usuario.
    $this->postJson('/api/auth/google', [
        'credential' => GoogleIdTokenFactory::token(),
    ])->assertOk();

    $this->postJson('/api/login', [
        'email' => 'ana@example.test',
        'password' => 'cualquier-cosa',
    ])->assertStatus(401);
});
