<?php

declare(strict_types=1);

/**
 * `Vector` is the piece that was missing from the write path: `details.embedding`
 * is `vector(768)` and Eloquent was being handed a raw PHP array, which PDO
 * cannot bind. Pure in and out, so no database here — the round trip through a
 * real column is exercised in tests/Feature/Jobs/GenerateEmbeddingForDetailTest.
 */

use App\Casts\Vector;
use App\Models\Detail;

function vectorCast(): Vector
{
    return new Vector;
}

it('escribe el array en el formato de texto de pgvector', function () {
    expect(vectorCast()->set(new Detail, 'embedding', [1.5, -2.0, 0.25], []))
        ->toBe('[1.5,-2,0.25]');
});

it('lee el formato de pgvector como array de floats', function () {
    $read = vectorCast()->get(new Detail, 'embedding', '[1.5,-2,0.25]', []);

    expect($read)->toBe([1.5, -2.0, 0.25]);
});

it('sobrevive la ida y vuelta', function () {
    $original = [0.1, 0.2, -0.3];

    $stored = vectorCast()->set(new Detail, 'embedding', $original, []);

    expect(vectorCast()->get(new Detail, 'embedding', $stored, []))->toBe($original);
});

it('deja pasar una cadena ya formateada sin tocarla', function () {
    // A raw query result written straight back must not be re-wrapped.
    expect(vectorCast()->set(new Detail, 'embedding', '[1,2,3]', []))->toBe('[1,2,3]');
});

it('conserva null en ambas direcciones', function () {
    expect(vectorCast()->set(new Detail, 'embedding', null, []))->toBeNull();
    expect(vectorCast()->get(new Detail, 'embedding', null, []))->toBeNull();
});

it('trata la cadena vacia como ausencia de vector', function () {
    expect(vectorCast()->get(new Detail, 'embedding', '', []))->toBeNull();
});

it('devuelve null cuando lo guardado no es un vector', function () {
    expect(vectorCast()->get(new Detail, 'embedding', 'no soy un vector', []))->toBeNull();
});

it('rechaza un valor que no se puede convertir en vector', function () {
    expect(fn () => vectorCast()->set(new Detail, 'embedding', 42, []))
        ->toThrow(InvalidArgumentException::class);
});

it('normaliza enteros a float al leer', function () {
    // pgvector prints whole numbers without a decimal part; the column is float.
    expect(vectorCast()->get(new Detail, 'embedding', '[1,2,3]', []))
        ->toBe([1.0, 2.0, 3.0]);
});
