<?php

declare(strict_types=1);

use App\Models\ChannelIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lista los canales en los que el usuario ya es alcanzable', function () {
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => '123456789',
    ]);

    $response = $this->getJson('/api/channel-identities', $this->actingAsJwtUser($user));

    $response->assertOk()->assertJsonPath('data.0.channel', 'telegram');
});

it('devuelve una lista vacia cuando no hay ninguna identidad, no un error', function () {
    $user = User::factory()->create();

    // Una cuenta sin vincular es el estado normal de un usuario nuevo. Si esto
    // respondiera 404 la vista tendria que tratar "todavia no vinculaste" como un
    // fallo, y ahi es donde aparece un mensaje de error para algo que no lo es.
    $this->getJson('/api/channel-identities', $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('nunca expone el external_id', function () {
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => '987654321',
    ]);

    $response = $this->getJson('/api/channel-identities', $this->actingAsJwtUser($user));

    // Un chat id es un identificador de persona: sirve para escribirle. La vista
    // no lo necesita para responder "esta vinculado", asi que no sale del backend.
    expect(array_keys($response->json('data.0')))->toBe(['channel', 'linked_at'])
        ->and($response->getContent())->not->toContain('987654321');
});

it('no filtra las identidades de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $otro->id,
        'channel' => 'telegram',
        'external_id' => '555000111',
    ]);

    $this->getJson('/api/channel-identities', $this->actingAsJwtUser($user))
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('exige autenticacion', function () {
    $this->getJson('/api/channel-identities')->assertUnauthorized();
});

it('devuelve todas las identidades cuando el usuario tiene mas de una', function () {
    $user = User::factory()->create();

    // El indice unico es (channel, external_id), no (user_id, channel): un usuario
    // puede sostener dos cuentas de Telegram legitimamente, y la respuesta no puede
    // colapsar eso a un booleano.
    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => '111111111',
        'linked_at' => now()->subDay(),
    ]);
    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => '222222222',
        'linked_at' => now(),
    ]);

    $response = $this->getJson('/api/channel-identities', $this->actingAsJwtUser($user));

    expect($response->json('data'))->toHaveCount(2);
});

it('ordena de forma determinista para que la lista no se reacomode entre pedidos', function () {
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => '333333333',
        'linked_at' => now(),
    ]);
    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'external_id' => '51987654321',
        'linked_at' => now()->subDay(),
    ]);

    $primera = $this->getJson('/api/channel-identities', $this->actingAsJwtUser($user))->json('data');
    $segunda = $this->getJson('/api/channel-identities', $this->actingAsJwtUser($user))->json('data');

    expect(array_column($primera, 'channel'))->toBe(['telegram', 'whatsapp'])
        ->and($segunda)->toBe($primera);
});
