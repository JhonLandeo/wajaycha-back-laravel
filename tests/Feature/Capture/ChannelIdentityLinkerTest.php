<?php

declare(strict_types=1);

use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Capture\ChannelIdentityLinker;
use App\Services\Capture\ChannelIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->linker = app(ChannelIdentityLinker::class);
});

it('vincula el telefono de un usuario recien registrado', function () {
    $user = User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);

    $identity = $this->linker->linkWhatsApp($user, '+51 999 888 777');

    expect($identity)->not->toBeNull()
        ->and($identity->external_id)->toBe('51999888777')
        ->and($identity->legacy_whatsapp_phone)->toBe('+51 999 888 777');
});

it('deja al usuario resoluble por el mismo identificador que manda Meta', function () {
    $user = User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);

    $this->linker->linkWhatsApp($user, '+51 999 888 777');

    // Meta envia el numero en E.164 sin el mas: exactamente lo que guardamos.
    expect(app(ChannelIdentityResolver::class)->resolve('whatsapp', '51999888777')?->id)
        ->toBe($user->id);
});

it('no vincula nada cuando el usuario no cargo telefono', function () {
    $user = User::factory()->create(['whatsapp_phone' => null]);

    expect($this->linker->linkWhatsApp($user, null))->toBeNull()
        ->and(ChannelIdentity::count())->toBe(0);
});

it('no vincula un telefono que no puede normalizar, pero no rompe el registro', function () {
    $user = User::factory()->create();

    expect($this->linker->linkWhatsApp($user, '12345'))->toBeNull()
        ->and(ChannelIdentity::count())->toBe(0);
});

it('no envenena la transaccion cuando pierde la carrera contra el indice unico', function () {
    $owner = User::factory()->create();
    $loser = User::factory()->create();

    // Simula la concurrencia de forma determinista: el ganador aterriza justo despues
    // de que el linker verifico que la cuenta estaba libre. Se inserta por query
    // builder para no disparar de nuevo este mismo evento.
    ChannelIdentity::creating(function () use ($owner) {
        DB::table('channel_identities')->insert([
            'user_id' => $owner->id,
            'channel' => 'whatsapp',
            'external_id' => '51999888777',
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $result = $this->linker->linkWhatsApp($loser, '+51 999 888 777');
    } finally {
        // En finally, igual que su hermano en ChannelLinkTokenTest: si la llamada
        // propagara, el listener quedaria vivo e insertaria esta fila fantasma en
        // cualquier test posterior que cree una ChannelIdentity.
        ChannelIdentity::flushEventListeners();
    }

    // Lo que se prueba es que el perdedor no vincula y que la transaccion sigue viva:
    // en PostgreSQL una violacion de constraint sin savepoint la aborta entera y esta
    // consulta reventaria con 25P02. El competidor simulado desaparece con el rollback
    // del savepoint porque, a diferencia de la concurrencia real, comparte transaccion.
    expect($result)->toBeNull()
        ->and(fn () => ChannelIdentity::count())->not->toThrow(Exception::class);

    $survivor = User::factory()->create();
    expect($this->linker->linkWhatsApp($survivor, '51888777666'))->not->toBeNull();
});

it('rehusa robarle la cuenta de canal a otro usuario', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $this->linker->linkWhatsApp($owner, '+51 999 888 777');

    expect($this->linker->linkWhatsApp($intruder, '51-999-888-777'))->toBeNull()
        ->and(ChannelIdentity::count())->toBe(1)
        ->and(app(ChannelIdentityResolver::class)->resolve('whatsapp', '51999888777')?->id)
        ->toBe($owner->id);
});
