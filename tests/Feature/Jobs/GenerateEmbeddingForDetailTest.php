<?php

declare(strict_types=1);

/**
 * This job is where the embedding backlog came from. Two separate defects met
 * here: `EmbeddingService` asked for 3072 dimensions against a `vector(768)`
 * column, and the job handed Eloquent a raw PHP array for a type PDO cannot
 * bind. Twenty-seven entries in `failed_jobs` and 0 of 1977 details with a
 * vector.
 *
 * The round trip through a real `vector(768)` column is the point of testing it
 * here rather than against a mock: a formatting mistake is invisible until
 * PostgreSQL rejects the value.
 */

use App\Jobs\GenerateEmbeddingForDetail;
use App\Models\Category;
use App\Models\Detail;
use App\Models\User;
use App\Services\EmbeddingService;

function detailEmbeddingVector(float $value = 0.5): array
{
    return array_fill(0, EmbeddingService::DIMENSIONS, $value);
}

function serviceReturning(?array $vector): EmbeddingService
{
    $mock = Mockery::mock(EmbeddingService::class);
    $mock->shouldReceive('generate')->andReturn($vector);

    return $mock;
}

it('guarda el vector en la columna, no una cadena rota', function () {
    $user = User::factory()->create();
    $detail = Detail::factory()->create(['user_id' => $user->id, 'embedding' => null]);

    (new GenerateEmbeddingForDetail($detail))->handle(serviceReturning(detailEmbeddingVector()));

    expect($detail->fresh()->embedding)->toHaveCount(EmbeddingService::DIMENSIONS);
});

it('guarda los valores que devolvio el servicio', function () {
    $user = User::factory()->create();
    $detail = Detail::factory()->create(['user_id' => $user->id, 'embedding' => null]);

    (new GenerateEmbeddingForDetail($detail))->handle(serviceReturning(detailEmbeddingVector(0.25)));

    expect($detail->fresh()->embedding[0])->toEqualWithDelta(0.25, 0.000001);
});

it('no toca el detail cuando el servicio no pudo generar nada', function () {
    $user = User::factory()->create();
    $detail = Detail::factory()->create(['user_id' => $user->id, 'embedding' => null]);

    (new GenerateEmbeddingForDetail($detail))->handle(serviceReturning(null));

    expect($detail->fresh()->embedding)->toBeNull();
});

it('registra la categoria usada cuando se la pasan', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create([
        'user_id' => $user->id,
        'embedding' => null,
        'last_used_category_id' => null,
    ]);

    (new GenerateEmbeddingForDetail($detail, $category->id))->handle(serviceReturning(detailEmbeddingVector()));

    expect($detail->fresh()->last_used_category_id)->toBe($category->id);
});

it('deja la categoria intacta cuando no le pasan ninguna', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create([
        'user_id' => $user->id,
        'embedding' => null,
        'last_used_category_id' => $category->id,
    ]);

    (new GenerateEmbeddingForDetail($detail))->handle(serviceReturning(detailEmbeddingVector()));

    expect($detail->fresh()->last_used_category_id)->toBe($category->id);
});

it('no registra categoria cuando el servicio fallo', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $detail = Detail::factory()->create([
        'user_id' => $user->id,
        'embedding' => null,
        'last_used_category_id' => null,
    ]);

    (new GenerateEmbeddingForDetail($detail, $category->id))->handle(serviceReturning(null));

    expect($detail->fresh()->last_used_category_id)->toBeNull();
});
