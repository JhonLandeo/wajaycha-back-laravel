<?php

declare(strict_types=1);

/**
 * The backfill spends money on an external API, once per row, over a table that
 * had 1977 details and zero embeddings. The properties worth guaranteeing are
 * therefore about restraint: it must not touch rows that already have a vector,
 * must stop exactly at `--limit`, and must call nothing at all under
 * `--dry-run`.
 *
 * `--sleep=0` throughout so the suite does not pay the pacing meant for Gemini.
 */

use App\Models\Detail;
use App\Models\User;
use App\Services\EmbeddingService;

function detailWithoutEmbedding(User $user, string $description = 'PANADERIA SAN MARTIN'): Detail
{
    return Detail::factory()->create([
        'user_id' => $user->id,
        'description' => $description,
        'embedding' => null,
    ]);
}

/**
 * @return array<float>
 */
function fakeVector(float $value = 1.0): array
{
    return array_fill(0, EmbeddingService::DIMENSIONS, $value);
}

function fakeEmbeddings(?array $vector, ?int $expectedCalls = null): void
{
    $mock = Mockery::mock(EmbeddingService::class);
    $expectation = $mock->shouldReceive('generate')->andReturn($vector);

    if ($expectedCalls !== null) {
        $expectation->times($expectedCalls);
    }

    app()->instance(EmbeddingService::class, $mock);
}

it('escribe el embedding de los details que no lo tienen', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user);
    fakeEmbeddings(fakeVector());

    $this->artisan('embeddings:backfill', ['--sleep' => 0])->assertSuccessful();

    expect(Detail::query()->whereNull('embedding')->count())->toBe(0);
});

it('no vuelve a pedir un embedding que ya existe', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user);
    Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'YA TIENE',
        'embedding' => fakeVector(0.5),
    ]);

    // Exactly one call: the row that already carries a vector is not a candidate.
    fakeEmbeddings(fakeVector(), expectedCalls: 1);

    $this->artisan('embeddings:backfill', ['--sleep' => 0])->assertSuccessful();
});

it('se detiene en el limite pedido', function () {
    $user = User::factory()->create();
    foreach (range(1, 5) as $i) {
        detailWithoutEmbedding($user, "COMERCIO {$i}");
    }

    fakeEmbeddings(fakeVector(), expectedCalls: 2);

    $this->artisan('embeddings:backfill', ['--limit' => 2, '--sleep' => 0])->assertSuccessful();

    expect(Detail::query()->whereNull('embedding')->count())->toBe(3);
});

it('deja la base intacta y no llama a Gemini en dry-run', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user);

    fakeEmbeddings(fakeVector(), expectedCalls: 0);

    $this->artisan('embeddings:backfill', ['--dry-run' => true, '--sleep' => 0])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    expect(Detail::query()->whereNull('embedding')->count())->toBe(1);
});

it('informa cuantas llamadas costaria la corrida completa', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $i) {
        detailWithoutEmbedding($user, "COMERCIO {$i}");
    }

    fakeEmbeddings(fakeVector(), expectedCalls: 0);

    $this->artisan('embeddings:backfill', ['--dry-run' => true, '--sleep' => 0])
        ->expectsOutputToContain('3 llamadas')
        ->assertSuccessful();
});

it('falla la corrida cuando algun detail no se pudo resolver', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user);

    // Null is what the service returns for both a transport error and an
    // unusable vector: the run has to end non-zero so a script notices.
    fakeEmbeddings(null);

    $this->artisan('embeddings:backfill', ['--sleep' => 0, '--retries' => 0])->assertFailed();

    expect(Detail::query()->whereNull('embedding')->count())->toBe(1);
});

it('reintenta antes de dar por perdido un detail', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user);

    // One initial attempt plus two retries.
    fakeEmbeddings(null, expectedCalls: 3);

    $this->artisan('embeddings:backfill', ['--sleep' => 0, '--retries' => 2])->assertFailed();
});

it('no gasta una llamada en un detail sin descripcion', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user, '   ');

    fakeEmbeddings(fakeVector(), expectedCalls: 0);

    $this->artisan('embeddings:backfill', ['--sleep' => 0])
        ->expectsOutputToContain('sin descripción')
        ->assertSuccessful();
});

/**
 * One pass over a mixed batch, asserting the counters rather than the side
 * effects. Every number in the summary is a decision an operator makes about
 * whether to run the next batch, so each is pinned.
 *
 * The row without a description sits in the middle on purpose: mutation turned
 * the `continue` that skips it into a `break`, which silently abandons the rest
 * of the chunk, and no single-row test could tell the difference.
 */
it('informa contadores exactos sobre un lote mixto', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user, 'PRIMERO');
    detailWithoutEmbedding($user, 'SEGUNDO');   // este falla
    detailWithoutEmbedding($user, '   ');       // sin descripción, se saltea
    detailWithoutEmbedding($user, 'CUARTO');

    $mock = Mockery::mock(EmbeddingService::class);
    $mock->shouldReceive('generate')
        ->times(3)
        ->andReturn(fakeVector(), null, fakeVector());
    app()->instance(EmbeddingService::class, $mock);

    // One exact assertion rather than five partial ones: Laravel matches each
    // `expectsOutputToContain` against a different slice of the output, and all
    // five counters live on the same line. Pinning the whole line also kills the
    // concatenation mutants that survived when only fragments were checked.
    $this->artisan('embeddings:backfill', ['--sleep' => 0, '--retries' => 0])
        ->expectsOutputToContain(
            'Backfill de embeddings: 4 details procesados, 2 escritos, 1 fallidos, '.
            '1 sin descripción. Quedan 2 sin embedding.'
        )
        ->assertFailed();

    // The fourth row proves the skip did not abort the pass.
    expect(Detail::query()->where('description', 'CUARTO')->sole()->embedding)->not->toBeNull();
});

it('expresa la duracion estimada en minutos cuando pasa del minuto', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $i) {
        detailWithoutEmbedding($user, "COMERCIO {$i}");
    }

    fakeEmbeddings(fakeVector(), expectedCalls: 0);

    // 3 llamadas × 30s = 90s.
    $this->artisan('embeddings:backfill', ['--dry-run' => true, '--sleep' => 30000])
        ->expectsOutputToContain('1min')
        ->assertSuccessful();
});

it('expresa la duracion estimada en segundos cuando es corta', function () {
    $user = User::factory()->create();
    detailWithoutEmbedding($user);

    fakeEmbeddings(fakeVector(), expectedCalls: 0);

    $this->artisan('embeddings:backfill', ['--dry-run' => true, '--sleep' => 2000])
        ->expectsOutputToContain('2s')
        ->assertSuccessful();
});

it('rechaza un limite que no tiene sentido', function () {
    $this->artisan('embeddings:backfill', ['--limit' => 0, '--sleep' => 0])->assertFailed();
});

it('avisa cuando no queda nada por rellenar', function () {
    $user = User::factory()->create();
    Detail::factory()->create([
        'user_id' => $user->id,
        'description' => 'YA TIENE',
        'embedding' => fakeVector(),
    ]);

    fakeEmbeddings(fakeVector(), expectedCalls: 0);

    $this->artisan('embeddings:backfill', ['--sleep' => 0])
        ->expectsOutputToContain('No hay nada que rellenar')
        ->assertSuccessful();
});
