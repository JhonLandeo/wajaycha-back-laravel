<?php

declare(strict_types=1);

use App\Support\FrontendOrigins;

/**
 * Quién puede llamar a la API desde un navegador.
 *
 * Vale la pena tener tests para lo que parece un `explode`: esta lista es una
 * decisión de seguridad, y sus dos formas de fallar son silenciosas. De más,
 * abre la API a un origen que nadie autorizó. De menos, rompe la aplicación
 * entera con un error que en el navegador se lee como "el servidor no responde".
 */
it('acepta el origen canonico solo', function () {
    expect(FrontendOrigins::allowed('https://wajaycha.com', null))
        ->toBe(['https://wajaycha.com']);
});

it('suma los origenes extra al canonico', function () {
    // La forma de una mudanza: el dominio nuevo es el canónico y el viejo sigue
    // entrando hasta que deje de servir la app.
    expect(FrontendOrigins::allowed(
        'https://app.wajaycha.com',
        'https://wajaycha.com,https://www.wajaycha.com',
    ))->toBe([
        'https://app.wajaycha.com',
        'https://wajaycha.com',
        'https://www.wajaycha.com',
    ]);
});

it('saca la barra final que el navegador nunca manda', function () {
    // El error más fácil de cometer y el más difícil de ver: `Origin` llega sin
    // barra, así que `https://app.wajaycha.com/` no matchea con nada y la
    // aplicación queda muerta sin un solo mensaje que lo explique.
    expect(FrontendOrigins::allowed('https://app.wajaycha.com/', null))
        ->toBe(['https://app.wajaycha.com']);
});

it('tolera espacios alrededor de las comas', function () {
    expect(FrontendOrigins::allowed('https://a.test', ' https://b.test , https://c.test '))
        ->toBe(['https://a.test', 'https://b.test', 'https://c.test']);
});

it('ignora entradas vacias en vez de autorizar la cadena vacia', function () {
    // Una coma de más no puede convertirse en un origen `''`. Depende del
    // paquete de CORS qué hace con eso, y no queremos averiguarlo en producción.
    expect(FrontendOrigins::allowed('https://a.test', ',,  ,'))
        ->toBe(['https://a.test']);
});

it('no repite un origen declarado dos veces', function () {
    expect(FrontendOrigins::allowed('https://a.test', 'https://a.test/,https://b.test'))
        ->toBe(['https://a.test', 'https://b.test']);
});

it('devuelve una lista vacia si no hay nada configurado', function () {
    // Sin orígenes no entra nadie. Es lo correcto: fallar cerrado. Un `*` por
    // defecto abriría la API de un registro financiero a cualquier sitio.
    expect(FrontendOrigins::allowed(null, null))->toBe([]);
    expect(FrontendOrigins::allowed('  ', ''))->toBe([]);
});
