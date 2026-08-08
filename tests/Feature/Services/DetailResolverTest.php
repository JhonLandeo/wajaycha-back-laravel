<?php

declare(strict_types=1);

use App\Models\Detail;
use App\Models\User;
use App\Services\DetailResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = app(DetailResolver::class);
});

it('reutiliza un Detail cuando la entidad limpia coincide exactamente', function () {
    $user = User::factory()->create();
    $existing = Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => 'supermercado metro',
    ]);

    $found = $this->resolver->findExisting($user->id, 'supermercado metro');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($existing->id);
});

it('reutiliza un Detail cuando la similitud supera el umbral de trigramas', function () {
    $user = User::factory()->create();
    $existing = Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => 'supermercado metro',
    ]);

    $found = $this->resolver->findExisting($user->id, 'supermercado metros');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($existing->id);
});

it('no reutiliza un Detail cuya entidad limpia es disimil', function () {
    $user = User::factory()->create();
    Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => 'supermercado metro',
    ]);

    expect($this->resolver->findExisting($user->id, 'grifo primax'))->toBeNull();
});

it('mantiene el umbral efectivo en 0.6', function () {
    expect(DetailResolver::THRESHOLD_TRIGRAM)->toBe(0.6);
});

it('resuelve dentro del alcance del usuario y nunca cruza cuentas', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    Detail::factory()->create([
        'user_id' => $owner->id,
        'entity_clean' => 'supermercado metro',
    ]);

    expect($this->resolver->findExisting($stranger->id, 'supermercado metro'))->toBeNull();
});

it('crea un Detail nuevo cuando no hay coincidencia', function () {
    $user = User::factory()->create();

    $detail = $this->resolver->resolveOrCreate($user->id, 'Grifo Primax', 'grifo primax', 'POS_GENERICO');

    expect(Detail::where('user_id', $user->id)->count())->toBe(1)
        ->and($detail->description)->toBe('Grifo Primax')
        ->and($detail->entity_clean)->toBe('grifo primax')
        ->and($detail->operation_type)->toBe('POS_GENERICO');
});

it('reutiliza el Detail existente en lugar de crear uno nuevo', function () {
    $user = User::factory()->create();
    $existing = Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => 'supermercado metro',
    ]);

    $detail = $this->resolver->resolveOrCreate($user->id, 'SUPERMERCADO METROS', 'supermercado metros', 'POS_GENERICO');

    expect($detail->id)->toBe($existing->id)
        ->and(Detail::where('user_id', $user->id)->count())->toBe(1);
});

it('reutiliza el Detail cuando lo guardado y lo entrante son ambos vacios', function () {
    // Sustituye a un test que decia "rellena la entidad limpia en blanco" y no
    // probaba nada: pasaba la misma cadena vacia como valor guardado y entrante,
    // asi que la asercion no distinguia si el backfill corria, se salteaba, o no
    // existia. Lo que si es cierto y vale fijar es que no duplica el Detail.
    $user = User::factory()->create();
    $existing = Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => '',
    ]);

    $detail = $this->resolver->resolveOrCreate($user->id, 'Desconocido', '', 'POS_GENERICO');

    expect($detail->id)->toBe($existing->id)
        ->and(Detail::where('user_id', $user->id)->count())->toBe(1);
});

it('no reutiliza un Detail cuya entidad limpia es nula', function () {
    // Documenta la conducta vigente: similarity(NULL, ?) no satisface el umbral y la
    // igualdad exacta tampoco alcanza NULL, asi que la fila queda fuera del match.
    $user = User::factory()->create();
    Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => null,
    ]);

    expect($this->resolver->findExisting($user->id, 'supermercado metro'))->toBeNull();
});

it('prefiere el Detail mas parecido cuando hay varios candidatos', function () {
    $user = User::factory()->create();
    Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => 'supermercado metroo',
    ]);
    $closest = Detail::factory()->create([
        'user_id' => $user->id,
        'entity_clean' => 'supermercado metro',
    ]);

    $found = $this->resolver->findExisting($user->id, 'supermercado metro');

    expect($found->id)->toBe($closest->id);
});
