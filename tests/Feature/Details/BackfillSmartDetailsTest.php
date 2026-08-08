<?php

declare(strict_types=1);

/**
 * `details:backfill-smart` rewrites `operation_type` and `entity_clean` across the
 * Detail catalogue, and had no test.
 *
 * It is a live artisan command that mutates rows in bulk, and those two columns are
 * what Entity Resolution matches on — `SmartMergeJob` filtered candidates by
 * `entity_clean` before it was deleted, and `DetailResolver` still leans on the same
 * shape. A backfill that mislabels them corrupts matching quietly and at scale.
 */

use App\Models\Detail;
use App\Models\User;

it('clasifica los details que todavia no tienen tipo de operacion', function () {
    $user = User::factory()->create();

    $detail = Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'YAPE A JUAN PEREZ',
        'operation_type' => null,
        'entity_clean' => null,
    ]);

    $this->artisan('details:backfill-smart')->assertSuccessful();

    $detail->refresh();

    expect($detail->operation_type)->not->toBeNull();
    expect($detail->entity_clean)->not->toBeNull();
});

it('tambien reprocesa los que quedaron marcados UNKNOWN', function () {
    $user = User::factory()->create();

    $detail = Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'COMPRA RAPPI LIMA',
        'operation_type' => 'UNKNOWN',
        'entity_clean' => null,
    ]);

    $this->artisan('details:backfill-smart')->assertSuccessful();

    expect($detail->refresh()->operation_type)->not->toBe('UNKNOWN');
});

it('no toca un detail que ya esta clasificado', function () {
    $user = User::factory()->create();

    $detail = Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'COMPRA RAPPI LIMA',
        'operation_type' => 'POS_GENERICO',
        'entity_clean' => 'CLASIFICADO A MANO',
    ]);

    $this->artisan('details:backfill-smart')->assertSuccessful();

    $detail->refresh();

    expect($detail->operation_type)->toBe('POS_GENERICO');
    expect($detail->entity_clean)->toBe('CLASIFICADO A MANO');
});

it('corre sin fallar cuando no hay nada que reparar', function () {
    $this->artisan('details:backfill-smart')->assertSuccessful();
});

it('procesa varios details en la misma corrida', function () {
    $user = User::factory()->create();

    foreach (['YAPE A ANA', 'PLIN A LUIS', 'COMPRA WONG'] as $description) {
        Detail::factory()->create([
            'user_id' => $user->id,
            'description' => $description,
            'operation_type' => null,
            'entity_clean' => null,
        ]);
    }

    $this->artisan('details:backfill-smart')->assertSuccessful();

    $unclassified = Detail::query()
        ->where('user_id', $user->id)
        ->where(fn ($q) => $q->whereNull('operation_type')->orWhereNull('entity_clean'))
        ->count();

    expect($unclassified)->toBe(0);
});
