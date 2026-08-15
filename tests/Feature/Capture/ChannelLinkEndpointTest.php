<?php

declare(strict_types=1);

use App\Models\ChannelLinkToken;
use App\Models\User;
use App\Services\Capture\ChannelLinkTokenIssuer;
use App\Services\Capture\ChannelLinkTokenRedeemer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.telegram.bot_username', 'wajaycha_bot');
});

it('entrega un deep link redimible al usuario autenticado', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/channel-link-token', [], $this->actingAsJwtUser($user));

    $response->assertOk()->assertJsonStructure(['deep_link', 'expires_at']);

    $deepLink = $response->json('deep_link');

    expect($deepLink)->toStartWith('https://t.me/wajaycha_bot?start=');

    // El token del enlace tiene que servir de verdad, no solo parecer un token.
    parse_str((string) parse_url($deepLink, PHP_URL_QUERY), $query);

    expect(ChannelLinkToken::sole()->token_hash)->toBe(hash('sha256', $query['start']))
        ->and(app(ChannelLinkTokenRedeemer::class)->redeem('telegram', '123456789', $query['start']))
        ->toBeTrue();
});

it('nunca devuelve el token suelto en la respuesta', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/channel-link-token', [], $this->actingAsJwtUser($user));

    // El token viaja solo dentro del deep link; exponerlo aparte invita a que
    // termine en un log de cliente o en una captura de pantalla.
    expect(array_keys($response->json()))->toBe(['deep_link', 'expires_at']);
});

it('exige autenticacion', function () {
    $this->postJson('/api/channel-link-token')->assertUnauthorized();

    expect(ChannelLinkToken::count())->toBe(0);
});

it('emite el token para quien esta logueado, no para un id que mande el cliente', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    $this->postJson('/api/channel-link-token', ['user_id' => $otro->id], $this->actingAsJwtUser($user));

    expect(ChannelLinkToken::sole()->user_id)->toBe($user->id);
});

it('aplica el TTL al vencimiento que devuelve y persiste', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/channel-link-token', [], $this->actingAsJwtUser($user));

    $esperado = now()->addMinutes(ChannelLinkTokenIssuer::TTL_MINUTES);

    expect(Carbon::parse($response->json('expires_at'))->diffInSeconds($esperado, true))->toBeLessThan(5)
        ->and(ChannelLinkToken::sole()->expires_at->diffInSeconds($esperado, true))->toBeLessThan(5);
});

it('falla fuerte y no emite nada si falta el username del bot', function () {
    config()->set('services.telegram.bot_username', null);

    $user = User::factory()->create();

    // Un enlace roto devuelto con 200 OK es peor que un error: nadie lo detecta.
    expect(fn () => app(ChannelLinkTokenIssuer::class)->issue($user))
        ->toThrow(RuntimeException::class);

    expect(ChannelLinkToken::count())->toBe(0);
});

it('corta al usuario que pide enlaces en rafaga', function () {
    $user = User::factory()->create();
    $headers = $this->actingAsJwtUser($user);

    $codigos = [];
    for ($i = 0; $i < 10; $i++) {
        $codigos[] = $this->postJson('/api/channel-link-token', [], $headers)->getStatusCode();
    }

    // Un token de vinculacion es una credencial al portador: emitirlos sin freno
    // multiplica las URLs vivas que pueden filtrarse, y llena la tabla gratis.
    expect($codigos)->toContain(429)
        ->and(array_search(429, $codigos, true))->toBeLessThanOrEqual(8);
});
