<?php

declare(strict_types=1);

/**
 * The relationship nothing was checking: how long a job's outbound calls can
 * take, against how long the worker running it is allowed to live.
 *
 * Adding retries to `config/http.php` silently multiplied every budget. The
 * Gemini profile went to 135 seconds of worst case — 45 seconds times three
 * attempts — inside a job that inherits Horizon's 60-second supervisor
 * default, with `tries: 1` so a killed job is never replayed. A retried Gemini
 * call therefore had the worker terminate it mid-flight and the sender receive
 * nothing, which is worse than the transient failure the retries were added to
 * survive.
 *
 * These cases derive the numbers from configuration rather than restating
 * them, so the guard survives the next edit to either side.
 */

use App\Jobs\ProcessTelegramCapture;
use App\Support\OutboundHttp;

/**
 * Every outbound call the photo capture path can make, in order: resolve the
 * file, download it, ask Gemini to read it, answer the sender — and once more
 * if the coach also speaks.
 *
 * @return string[]
 */
function telegramCapturePhotoPathProfiles(): array
{
    return ['telegram', 'telegram', 'gemini', 'telegram_send', 'telegram_send'];
}

function telegramCapturePhotoPathWorstCase(): int
{
    return array_sum(array_map(
        fn (string $profile): int => OutboundHttp::worstCaseSecondsFor($profile),
        telegramCapturePhotoPathProfiles(),
    ));
}

it('calcula el peor caso de un perfil sumando intentos y esperas', function () {
    config()->set('http.profiles.presupuesto-de-prueba', [
        'timeout' => 10,
        'retries' => 2,
        'retry_base_delay_ms' => 250,
        'retry_max_delay_ms' => 5000,
    ]);

    // Tres intentos de 10 s, mas 250 ms y 500 ms de espera entre ellos: 30,75 s,
    // que se redondea hacia arriba para no reportar un presupuesto mas chico de
    // lo que realmente es.
    expect(OutboundHttp::worstCaseSecondsFor('presupuesto-de-prueba'))->toBe(31);
});

it('no reporta espera cuando el perfil no reintenta', function () {
    config()->set('http.profiles.sin-reintento', [
        'timeout' => 12,
        'retries' => 0,
        'retry_base_delay_ms' => 250,
        'retry_max_delay_ms' => 5000,
    ]);

    expect(OutboundHttp::worstCaseSecondsFor('sin-reintento'))->toBe(12);
});

it('respeta el techo de espera al calcular el peor caso', function () {
    config()->set('http.profiles.espera-larga', [
        'timeout' => 1,
        'retries' => 3,
        'retry_base_delay_ms' => 60_000,
        'retry_max_delay_ms' => 1_000,
    ]);

    // Cuatro intentos de 1 s mas tres esperas topeadas en 1 s cada una.
    expect(OutboundHttp::worstCaseSecondsFor('espera-larga'))->toBe(7);
});

// ------------------------------------------------------- el invariante real

it('el timeout del job de captura cubre todo lo que el job puede salir a pedir', function () {
    $presupuesto = telegramCapturePhotoPathWorstCase();
    $delJob = (new ProcessTelegramCapture('1', 'texto'))->timeout;

    // ESTE es el caso. Subir un timeout o un reintento en config/http.php sin
    // revisar el job lo rompe, que es exactamente lo que no pasaba antes: el
    // presupuesto de Gemini crecio a 135 s contra un worker de 60 y nada lo noto.
    expect($delJob)->toBeGreaterThanOrEqual($presupuesto);
});

it('el job declara su propio timeout en vez de heredar el del supervisor', function () {
    $supervisor = (int) config('horizon.defaults.supervisor-1.timeout');
    $presupuesto = telegramCapturePhotoPathWorstCase();

    // El default de Horizon no alcanza para esta ruta, y por eso el job tiene
    // que declarar el suyo. Si algun dia el presupuesto baja lo suficiente como
    // para entrar en el default, este caso avisa que la propiedad sobra.
    expect($presupuesto)->toBeGreaterThan($supervisor)
        ->and((new ProcessTelegramCapture('1', 'texto'))->timeout)->toBeGreaterThan($supervisor);
});

/**
 * El menu no declara timeout propio, y eso es una afirmacion sobre su
 * presupuesto, no un olvido: cierra el callback y manda una respuesta, dos
 * llamadas al mismo perfil de escritura y nada mas. Entra en el default del
 * supervisor con margen.
 *
 * Lo que este caso custodia es el dia que deje de entrar. Subir `retries` en
 * `telegram_send` multiplica las dos llamadas a la vez, y sin este guard el job
 * simplemente moriria a mitad: el usuario se queda sin respuesta y con el boton
 * girando, que es la forma mas silenciosa posible de romperlo.
 */
it('el presupuesto del job de menu entra en el worker sin declarar timeout propio', function () {
    $supervisor = (int) config('horizon.defaults.supervisor-1.timeout');

    $presupuesto = OutboundHttp::worstCaseSecondsFor('telegram_send') * 2;

    expect($presupuesto)->toBeLessThan(
        $supervisor,
        'la ruta del menu ya no entra en el worker: ProcessTelegramMenu tiene que declarar su propio timeout',
    );
});

it('ningun perfil solo agota por si mismo el worker del supervisor', function () {
    $supervisor = (int) config('horizon.defaults.supervisor-1.timeout');

    /** @var array<string, mixed> $perfiles */
    $perfiles = config('http.profiles');

    foreach (array_keys($perfiles) as $perfil) {
        // Un unico perfil que ya no entra en el worker no deja lugar para las
        // otras llamadas de la misma ruta, ni para escribir en la base despues.
        expect(OutboundHttp::worstCaseSecondsFor((string) $perfil))
            ->toBeLessThan($supervisor, "el perfil {$perfil} solo ya no entra en el worker");
    }
});
