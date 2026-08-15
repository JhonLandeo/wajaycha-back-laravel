<?php

declare(strict_types=1);

use App\Models\ChannelLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('borra los tokens vencidos y los ya gastados, y respeta los vivos', function () {
    $user = User::factory()->create();

    ChannelLinkToken::factory()->for($user)->expired()->create();
    ChannelLinkToken::factory()->for($user)->redeemed()->create();
    $vivo = ChannelLinkToken::factory()->for($user)->create();

    $this->artisan('channel-link-tokens:prune')->assertSuccessful();

    expect(ChannelLinkToken::pluck('id')->all())->toBe([$vivo->id]);
});

it('borra un token gastado aunque todavia no haya vencido', function () {
    $user = User::factory()->create();

    // El OR importa: un token redimido hace un minuto sigue dentro de su ventana
    // de validez, y aun asi no tiene por que seguir existiendo.
    ChannelLinkToken::factory()->for($user)->redeemed()->create();

    $this->artisan('channel-link-tokens:prune')->assertSuccessful();

    expect(ChannelLinkToken::count())->toBe(0);
});

it('no borra nada cuando todos los tokens estan vivos', function () {
    $user = User::factory()->create();
    ChannelLinkToken::factory()->for($user)->count(3)->create();

    $this->artisan('channel-link-tokens:prune')->assertSuccessful();

    expect(ChannelLinkToken::count())->toBe(3);
});
