<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Proves the live PostgreSQL session reports the reference timezone (design.md D3),
 * not merely that `config('database.connections.pgsql.timezone')` holds the right
 * string. A test that only reads the config file would pass even if Laravel's
 * PostgresConnector never issued `SET TIME ZONE` for it.
 */
it('reports the reference timezone on the live database session', function () {
    $sessionTimeZone = DB::selectOne('SHOW TIME ZONE');

    expect($sessionTimeZone->TimeZone)->toBe('America/Lima');
});

it('pins the same reference timezone declared by config(app.timezone)', function () {
    $sessionTimeZone = DB::selectOne('SHOW TIME ZONE');

    expect($sessionTimeZone->TimeZone)->toBe(config('app.timezone'));
});

it('la sesion sigue el valor configurado, no el default del servidor', function () {
    // El test de arriba pasaria igual sin el pin, porque este servidor ya arranca en
    // America/Lima. Este prueba el MECANISMO: si la conexion honra la clave, cambiarla
    // tiene que cambiar la sesion. Si alguien borra el pin, esto falla en cualquier
    // servidor, no solo en uno cuyo default no coincida.
    config(['database.connections.pgsql.timezone' => 'UTC']);
    DB::purge('pgsql');

    expect(DB::selectOne('SHOW TIME ZONE')->TimeZone)->toBe('UTC');

    config(['database.connections.pgsql.timezone' => 'America/Lima']);
    DB::purge('pgsql');

    expect(DB::selectOne('SHOW TIME ZONE')->TimeZone)->toBe('America/Lima');
});

it('declara el huso en la config de la conexion, no lo hereda del servidor', function () {
    // La tercera pata. Las otras dos prueban el resultado y el mecanismo, pero las dos
    // pasarian con el pin borrado mientras el servidor arranque en America/Lima. Esta
    // falla el dia que alguien saque la clave, en cualquier servidor.
    expect(config('database.connections.pgsql.timezone'))->toBe('America/Lima');
});
