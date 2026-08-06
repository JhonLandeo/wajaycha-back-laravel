<?php

declare(strict_types=1);

use App\Console\Commands\PruneProcessedChannelUpdates;
use App\Models\ProcessedChannelUpdate;
use App\Services\Capture\ChannelUpdateDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dedup = app(ChannelUpdateDeduplicator::class);
});

it('acepta la primera vez que ve una entrega', function () {
    expect($this->dedup->claim('telegram', '1001'))->toBeTrue()
        ->and(ProcessedChannelUpdate::count())->toBe(1);
});

it('descarta la reentrega del mismo update', function () {
    $this->dedup->claim('telegram', '1001');

    expect($this->dedup->claim('telegram', '1001'))->toBeFalse()
        ->and(ProcessedChannelUpdate::count())->toBe(1);
});

it('trata el mismo id en dos canales como dos entregas distintas', function () {
    expect($this->dedup->claim('telegram', '1001'))->toBeTrue()
        ->and($this->dedup->claim('whatsapp', '1001'))->toBeTrue()
        ->and(ProcessedChannelUpdate::count())->toBe(2);
});

it('sobrevive a un reinicio, porque la memoria vive en la base', function () {
    $this->dedup->claim('telegram', '1001');

    // Un deduplicador nuevo, como el que armaria un worker recien arrancado.
    expect(app()->make(ChannelUpdateDeduplicator::class)->claim('telegram', '1001'))->toBeFalse();
});

it('deja que gane uno solo cuando dos procesos reclaman el mismo update', function () {
    // Simula de forma determinista al competidor que aterriza entre nuestro intento
    // y el commit: se inserta por query builder para no re-disparar este evento.
    ProcessedChannelUpdate::creating(function () {
        DB::table('processed_channel_updates')->insert([
            'channel' => 'telegram',
            'external_update_id' => '1001',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $resultado = $this->dedup->claim('telegram', '1001');
    } finally {
        ProcessedChannelUpdate::flushEventListeners();
    }

    // Pierde limpio: false, no excepcion. Y la transaccion que nos envuelve sigue
    // viva, cosa que se comprueba haciendo otra consulta despues.
    expect($resultado)->toBeFalse()
        ->and($this->dedup->claim('telegram', '2002'))->toBeTrue();
});

it('no reclama nada cuando el id viene vacio... y lo deja explicito', function () {
    // Un update sin id no se puede deduplicar. Se acepta a proposito: es preferible
    // arriesgar un duplicado a descartar en silencio un movimiento real. Quien llame
    // decide; este test existe para que la conducta sea visible y no un accidente.
    expect($this->dedup->claim('telegram', ''))->toBeTrue()
        ->and($this->dedup->claim('telegram', ''))->toBeFalse();
});

it('borra las entregas mas viejas que la ventana de reintento', function () {
    ProcessedChannelUpdate::factory()->create([
        'processed_at' => now()->subDays(PruneProcessedChannelUpdates::RETENTION_DAYS + 1),
    ]);
    $reciente = ProcessedChannelUpdate::factory()->create(['processed_at' => now()->subHour()]);

    $this->artisan('processed-channel-updates:prune')->assertSuccessful();

    expect(ProcessedChannelUpdate::pluck('id')->all())->toBe([$reciente->id]);
});

it('conserva la entrega que cae en el instante exacto del corte', function () {
    ProcessedChannelUpdate::factory()->create([
        'processed_at' => now()->subDays(PruneProcessedChannelUpdates::RETENTION_DAYS),
    ]);

    $this->artisan('processed-channel-updates:prune')->assertSuccessful();

    // El corte es estrictamente "mas viejo que", no "tan viejo como". En la duda se
    // conserva: perder una entrega recordada duplica una transaccion, guardarla de
    // mas solo ocupa una fila.
    expect(ProcessedChannelUpdate::count())->toBe(1);
});

it('no borra una entrega justo dentro de la ventana', function () {
    ProcessedChannelUpdate::factory()->create([
        'processed_at' => now()->subDays(PruneProcessedChannelUpdates::RETENTION_DAYS)->addMinute(),
    ]);

    $this->artisan('processed-channel-updates:prune')->assertSuccessful();

    expect(ProcessedChannelUpdate::count())->toBe(1);
});
