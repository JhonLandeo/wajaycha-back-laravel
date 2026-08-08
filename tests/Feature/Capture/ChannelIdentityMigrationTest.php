<?php

declare(strict_types=1);

use App\Models\ChannelIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Exercises the real migration file rather than a service standing in for it, because
 * the migration owns its own copy of the normalisation on purpose.
 */
function channelIdentityMigration(): object
{
    return require database_path('migrations/2026_08_06_120000_create_channel_identities_table.php');
}

function runChannelIdentityMigration(): void
{
    Schema::dropIfExists('channel_identities');

    channelIdentityMigration()->up();
}

it('copia el telefono normalizado y conserva el original', function () {
    $user = User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);

    runChannelIdentityMigration();

    $identity = ChannelIdentity::where('user_id', $user->id)->sole();

    expect($identity->channel)->toBe('whatsapp')
        ->and($identity->external_id)->toBe('51999888777')
        ->and($identity->legacy_whatsapp_phone)->toBe('+51 999 888 777');
});

it('deja intacto users.whatsapp_phone, que es lo que hace reversible el down()', function () {
    $user = User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);

    runChannelIdentityMigration();

    expect($user->fresh()->whatsapp_phone)->toBe('+51 999 888 777');
});

it('ignora usuarios sin telefono cargado', function () {
    User::factory()->create(['whatsapp_phone' => null]);

    runChannelIdentityMigration();

    expect(ChannelIdentity::count())->toBe(0);
});

it('aborta ante una fila que no puede normalizar en lugar de adivinar', function () {
    User::factory()->create(['whatsapp_phone' => 'no-es-un-telefono']);

    expect(fn () => runChannelIdentityMigration())->toThrow(RuntimeException::class);
});

it('aborta ante un numero demasiado corto para ser E164', function () {
    User::factory()->create(['whatsapp_phone' => '1234567']);

    expect(fn () => runChannelIdentityMigration())->toThrow(RuntimeException::class);
});

it('acepta el minimo exacto de ocho digitos', function () {
    User::factory()->create(['whatsapp_phone' => '12345678']);

    runChannelIdentityMigration();

    expect(ChannelIdentity::sole()->external_id)->toBe('12345678');
});

it('acepta el maximo exacto de quince digitos', function () {
    User::factory()->create(['whatsapp_phone' => '123456789012345']);

    runChannelIdentityMigration();

    expect(ChannelIdentity::sole()->external_id)->toBe('123456789012345');
});

it('aborta ante un numero de dieciseis digitos, uno mas que E164', function () {
    User::factory()->create(['whatsapp_phone' => '1234567890123456']);

    expect(fn () => runChannelIdentityMigration())->toThrow(RuntimeException::class);
});

it('aborta ante una colision de normalizacion en lugar de pisar una identidad', function () {
    User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);
    User::factory()->create(['whatsapp_phone' => '51-999-888-777']);

    expect(fn () => runChannelIdentityMigration())->toThrow(RuntimeException::class);
});

it('no deja ninguna identidad a medio migrar cuando aborta', function () {
    User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);
    User::factory()->create(['whatsapp_phone' => 'no-es-un-telefono']);

    try {
        runChannelIdentityMigration();
    } catch (RuntimeException) {
        // esperado: lo que se prueba es el estado que deja, no la excepcion
    }

    expect(ChannelIdentity::count())->toBe(0);
});

it('registra cada fila que migra', function () {
    Log::spy();

    $user = User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);

    runChannelIdentityMigration();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => str_contains($message, (string) $user->id)
            && str_contains($message, '51999888777'))
        ->once();
});

it('crea el indice unico que impide dos usuarios sobre la misma cuenta de canal', function () {
    runChannelIdentityMigration();

    $first = User::factory()->create();
    $second = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $first->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);

    expect(fn () => ChannelIdentity::factory()->create([
        'user_id' => $second->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('deja los telefonos originales intactos al revertir', function () {
    $user = User::factory()->create(['whatsapp_phone' => '+51 999 888 777']);

    runChannelIdentityMigration();

    expect(ChannelIdentity::count())->toBe(1);

    channelIdentityMigration()->down();

    // down() solo suelta la tabla. Los valores originales nunca se movieron de
    // users.whatsapp_phone, y eso es exactamente lo que hace reversible al cambio.
    expect(Schema::hasTable('channel_identities'))->toBeFalse()
        ->and($user->fresh()->whatsapp_phone)->toBe('+51 999 888 777');
});
