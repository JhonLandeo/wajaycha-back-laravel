<?php

declare(strict_types=1);

use App\Models\ChannelIdentity;
use App\Models\ChannelLinkToken;
use App\Models\User;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\ChannelLinkTokenIssuer;
use App\Services\Capture\ChannelLinkTokenRedeemer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.telegram.bot_username', 'wajaycha_bot');

    $this->issuer = app(ChannelLinkTokenIssuer::class);
    $this->redeemer = app(ChannelLinkTokenRedeemer::class);
});

it('vincula la cuenta de canal al redimir un token valido', function () {
    $user = User::factory()->create();
    $token = $this->issuer->issue($user)->token;

    expect($this->redeemer->redeem('telegram', '123456789', $token))->toBeTrue()
        ->and(app(ChannelIdentityResolver::class)->resolve('telegram', '123456789')?->id)
        ->toBe($user->id);
});

it('nunca guarda el token, solo su hash', function () {
    $user = User::factory()->create();
    $issued = $this->issuer->issue($user);

    $stored = ChannelLinkToken::sole();

    expect($stored->token_hash)->not->toBe($issued->token)
        ->and($stored->token_hash)->toBe(hash('sha256', $issued->token))
        ->and($stored->getAttributes())->not->toHaveKey('token');
});

it('rechaza un token ya redimido', function () {
    $user = User::factory()->create();
    $token = $this->issuer->issue($user)->token;

    $this->redeemer->redeem('telegram', '111', $token);

    expect($this->redeemer->redeem('telegram', '222', $token))->toBeFalse()
        ->and(ChannelIdentity::count())->toBe(1);
});

it('rechaza un token vencido usando un reloj controlado, no un sleep', function () {
    $user = User::factory()->create();
    $token = $this->issuer->issue($user)->token;

    $this->travel(ChannelLinkTokenIssuer::TTL_MINUTES + 1)->minutes();

    expect($this->redeemer->redeem('telegram', '123456789', $token))->toBeFalse()
        ->and(ChannelIdentity::count())->toBe(0);
});

it('acepta el token justo antes de que venza', function () {
    $user = User::factory()->create();
    $token = $this->issuer->issue($user)->token;

    $this->travel(ChannelLinkTokenIssuer::TTL_MINUTES - 1)->minutes();

    expect($this->redeemer->redeem('telegram', '123456789', $token))->toBeTrue();
});

it('rechaza un token inventado', function () {
    User::factory()->create();

    expect($this->redeemer->redeem('telegram', '123456789', 'token-falsificado'))->toBeFalse()
        ->and(ChannelIdentity::count())->toBe(0);
});

it('rechaza vincular una cuenta de canal que ya pertenece a otro usuario', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $owner->id,
        'channel' => 'telegram',
        'external_id' => '123456789',
    ]);

    $token = $this->issuer->issue($intruder)->token;

    expect($this->redeemer->redeem('telegram', '123456789', $token))->toBeFalse()
        ->and(app(ChannelIdentityResolver::class)->resolve('telegram', '123456789')?->id)
        ->toBe($owner->id);
});

it('no gasta el token cuando la cuenta de canal ya esta tomada', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $owner->id,
        'channel' => 'telegram',
        'external_id' => '123456789',
    ]);

    $token = $this->issuer->issue($intruder)->token;
    $this->redeemer->redeem('telegram', '123456789', $token);

    // El token sigue vivo: el intruso no le quemo el enlace a su dueño legitimo.
    expect(ChannelLinkToken::sole()->redeemed_at)->toBeNull()
        ->and($this->redeemer->redeem('telegram', '999999999', $token))->toBeTrue();
});

it('nunca escribe el token en los logs', function () {
    Log::spy();

    $user = User::factory()->create();
    $token = $this->issuer->issue($user)->token;

    $this->redeemer->redeem('telegram', '123456789', $token);
    $this->redeemer->redeem('telegram', '999', 'token-falsificado');

    Log::shouldNotHaveReceived('info', fn (string $m) => str_contains($m, $token));
    Log::shouldNotHaveReceived('warning', fn (string $m) => str_contains($m, $token));
});

it('devuelve exactamente la misma respuesta para todo rechazo, sin decir por que', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    // 1. Token inventado.
    $forjado = $this->redeemer->redeem('telegram', '111', 'token-falsificado');

    // 2. Token vencido.
    $vencido = $this->issuer->issue($user)->token;
    $this->travel(ChannelLinkTokenIssuer::TTL_MINUTES + 1)->minutes();
    $expirado = $this->redeemer->redeem('telegram', '222', $vencido);
    $this->travelBack();

    // 3. Token ya redimido.
    $usado = $this->issuer->issue($user)->token;
    $this->redeemer->redeem('telegram', '333', $usado);
    $redimido = $this->redeemer->redeem('telegram', '444', $usado);

    // 4. Cuenta de canal ya tomada.
    ChannelIdentity::factory()->create([
        'user_id' => $owner->id,
        'channel' => 'telegram',
        'external_id' => '555',
    ]);
    $tomada = $this->redeemer->redeem('telegram', '555', $this->issuer->issue($user)->token);

    // Todos false y nada mas: quien redime no puede distinguir "no existe" de
    // "vencido" de "ya usado", asi que no puede sondear que tokens son reales.
    expect([$forjado, $expirado, $redimido, $tomada])->toBe([false, false, false, false]);
});

it('no revienta cuando otro token gana la carrera por la misma cuenta de canal', function () {
    $primero = User::factory()->create();
    $segundo = User::factory()->create();

    $token = $this->issuer->issue($segundo)->token;

    // Simula la concurrencia de forma determinista: el ganador aterriza despues de
    // que el redeemer verifico que la cuenta estaba libre. El lock es sobre la fila
    // del token, no sobre la identidad, asi que este hueco existe de verdad.
    ChannelIdentity::creating(function () use ($primero) {
        DB::table('channel_identities')->insert([
            'user_id' => $primero->id,
            'channel' => 'telegram',
            'external_id' => '123456789',
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $resultado = $this->redeemer->redeem('telegram', '123456789', $token);
    } finally {
        // En finally a proposito: si redeem() llegara a propagar, el listener quedaria
        // vivo e insertaria esta fila fantasma en cualquier test posterior que cree una
        // ChannelIdentity, y el fallo aparecería lejos de su causa.
        ChannelIdentity::flushEventListeners();
    }

    // Rechazo, no excepcion: el contrato dice que todo rechazo se ve igual.
    expect($resultado)->toBeFalse();

    // El token no se gasta, asi que sigue sirviendo en otra cuenta. Lo que esta
    // simulacion NO puede mostrar es que la fila del ganador sobreviva: comparte
    // transaccion con nosotros, asi que el rollback al savepoint tambien se la lleva.
    // En concurrencia real el ganador esta en otra conexion y ya commiteo.
    expect($this->redeemer->redeem('telegram', '999999999', $token))->toBeTrue();
});

it('acepta el token en el instante exacto del vencimiento', function () {
    $user = User::factory()->create();
    $token = $this->issuer->issue($user)->token;

    // El corte es estrictamente "ya paso", no "llego": justo en expires_at el token
    // todavia vale. Se fija para que nadie cambie isPast() por isSameOrBefore() sin
    // que un test se entere.
    $this->travelTo(ChannelLinkToken::sole()->expires_at);

    expect($this->redeemer->redeem('telegram', '123456789', $token))->toBeTrue();
});
