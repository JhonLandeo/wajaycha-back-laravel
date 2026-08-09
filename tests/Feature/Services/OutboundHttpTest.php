<?php

declare(strict_types=1);

use App\Support\OutboundHttp;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The outbound policy, tested where it is decided rather than once per service.
 *
 * Before this class existed, not one call in `app/` declared a timeout or a
 * retry. The gap was invisible precisely because nothing asserted it: every
 * service handled `! $response->successful()` correctly, so the suite was green
 * while a one-second 503 from Gemini still cost a user their receipt.
 *
 * The case that matters most here is 'devuelve la respuesta fallida en vez de
 * lanzar'. Laravel flips `retry()` to throwing the moment a retry budget is
 * configured, and every caller in this codebase reads the response instead of
 * catching. Getting that wrong would have turned each of those branches into an
 * uncaught exception — a change nothing else in the suite would have caught.
 */
it('reintenta un 500 hasta agotar el presupuesto del perfil', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    // 2 reintentos sobre el primer intento, segun config('http.defaults.retries').
    Http::assertSentCount(3);
});

it('devuelve la respuesta fallida en vez de lanzar', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $response = OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    // Con el `throw: true` que Laravel activa solo por configurar reintentos,
    // esta linea seria una RequestException sin atrapar y cada
    // `if (! $response->successful())` del codigo quedaria muerto.
    expect($response->status())->toBe(500)
        ->and($response->body())->toBe('boom');
});

it('no reintenta un 4xx que no sea 429', function () {
    Http::fake(['*' => Http::response('nope', 400)]);

    OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    // Insistir con un pedido mal formado gasta latencia y, contra Gemini, tokens.
    Http::assertSentCount(1);
});

it('reintenta un 429 porque es transitorio por definicion', function () {
    Http::fake(['*' => Http::response('slow down', 429)]);

    OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    Http::assertSentCount(3);
});

it('reintenta cuando ni siquiera se pudo conectar', function () {
    $intentos = 0;

    Http::fake(function () use (&$intentos) {
        $intentos++;

        throw new ConnectionException('Connection timed out');
    });

    expect(fn () => OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso'))
        ->toThrow(ConnectionException::class);

    // Una caida de conexion sigue propagando cuando se agotan los intentos —
    // eso no cambio. Lo que cambio es que ahora hay intentos.
    expect($intentos)->toBe(3);
});

it('deja de reintentar apenas la respuesta sale bien', function () {
    Http::fake(['*' => Http::sequence()
        ->push('boom', 503)
        ->push(['ok' => true], 200)]);

    $response = OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    expect($response->successful())->toBeTrue();

    Http::assertSentCount(2);
});

it('respeta el presupuesto mas corto del perfil de escritura', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    OutboundHttp::to('telegram_send')->post('https://ejemplo.test/recurso');

    // `telegram_send` baja a un reintento porque sendMessage no es idempotente.
    Http::assertSentCount(2);
});

it('obedece el Retry-After que pide el servidor en vez de su propio backoff', function () {
    Sleep::fake();
    config()->set('http.defaults.retry_base_delay_ms', 250);

    Http::fake(['*' => Http::sequence()
        ->push('slow down', 429, ['Retry-After' => '2'])
        ->push(['ok' => true], 200)]);

    OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    // El backoff propio hubiera esperado 250 ms y caido dentro de la misma
    // ventana que el servidor acaba de cerrar. Asi es como un 429 se convierte
    // en un bloqueo.
    Sleep::assertSlept(fn (CarbonInterval $duracion) => (int) $duracion->totalMilliseconds === 2000);
});

it('entiende tambien el retry_after que Telegram manda en el cuerpo', function () {
    Sleep::fake();
    config()->set('http.defaults.retry_base_delay_ms', 250);

    Http::fake(['*' => Http::sequence()
        ->push(['ok' => false, 'parameters' => ['retry_after' => 3]], 429)
        ->push(['ok' => true], 200)]);

    OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    // Telegram no siempre manda la cabecera estandar: pone el numero adentro
    // del JSON. Leer solo una de las dos formas deja media defensa.
    Sleep::assertSlept(fn (CarbonInterval $duracion) => (int) $duracion->totalMilliseconds === 3000);
});

it('nunca espera mas que el techo configurado', function () {
    Sleep::fake();
    config()->set('http.defaults.retry_base_delay_ms', 250);
    config()->set('http.defaults.retry_max_delay_ms', 5000);

    Http::fake(['*' => Http::sequence()
        ->push('slow down', 429, ['Retry-After' => '600'])
        ->push(['ok' => true], 200)]);

    OutboundHttp::to('telegram')->get('https://ejemplo.test/recurso');

    // Telegram puede pedir esperas de minutos. Obedecer diez minutos dejaria un
    // worker de la cola parado ahi, que es peor que perder el mensaje.
    Sleep::assertSlept(fn (CarbonInterval $duracion) => (int) $duracion->totalMilliseconds === 5000);
});

it('rechaza un perfil que no existe en vez de salir sin politica', function () {
    // El modo de falla que esta clase existe para terminar es "una llamada sin
    // limite". Un nombre mal tipeado no puede degradar en silencio hasta ahi.
    expect(fn () => OutboundHttp::to('perfil-que-no-existe'))
        ->toThrow(InvalidArgumentException::class);
});
