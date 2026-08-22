<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * La otra mitad de {@see \Tests\Unit\Support\FrontendOriginsTest}.
 *
 * Aquel prueba que la lista se arma bien; este prueba que la lista SE APLICA.
 * Son cosas distintas y una puede estar bien con la otra rota: `HandleCors` vive
 * en el stack global de Laravel 11 y no aparece en `bootstrap/app.php`, así que
 * nada en este repositorio dice en voz alta que `config/cors.php` esté conectado
 * a algo. Estos tests lo dicen.
 */
it('autoriza al origen canonico', function () {
    config(['cors.allowed_origins' => ['https://app.wajaycha.com']]);

    $this->withHeader('Origin', 'https://app.wajaycha.com')
        ->postJson('/api/login', ['email' => 'nadie@example.test', 'password' => 'x'])
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.wajaycha.com');
});

it('autoriza tambien al dominio viejo durante una mudanza', function () {
    config(['cors.allowed_origins' => ['https://app.wajaycha.com', 'https://wajaycha.com']]);

    $this->withHeader('Origin', 'https://wajaycha.com')
        ->postJson('/api/login', ['email' => 'nadie@example.test', 'password' => 'x'])
        ->assertHeader('Access-Control-Allow-Origin', 'https://wajaycha.com');
});

it('no le pone la cabecera a un origen que no esta en la lista', function () {
    // Sin esta cabecera el navegador descarta la respuesta. Es la unica defensa
    // contra que otro sitio lea la API con la sesion de quien lo visita.
    config(['cors.allowed_origins' => ['https://app.wajaycha.com', 'https://wajaycha.com']]);

    $this->withHeader('Origin', 'https://sitio-ajeno.test')
        ->postJson('/api/login', ['email' => 'nadie@example.test', 'password' => 'x'])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('declara Vary: Origin cuando la respuesta depende de quien pregunta', function () {
    // Medido, no supuesto: con UN solo origen configurado la cabecera es
    // constante y el paquete la manda siempre, incluso a un origen ajeno —el
    // navegador la rechaza igual porque no coincide con el suyo—. Con VARIOS,
    // la respuesta cambia segun quien pregunta, y ahi `Vary` deja de ser un
    // detalle: sin el, un CDN puede cachear la respuesta de un origen y
    // servirsela a otro. La lista pasa a tener mas de uno durante la mudanza a
    // app.wajaycha.com, asi que esto empieza a importar ahora.
    config(['cors.allowed_origins' => ['https://app.wajaycha.com', 'https://wajaycha.com']]);

    $this->withHeader('Origin', 'https://wajaycha.com')
        ->postJson('/api/login', ['email' => 'nadie@example.test', 'password' => 'x'])
        ->assertHeader('Access-Control-Allow-Origin', 'https://wajaycha.com')
        ->assertHeader('Vary', 'Origin');
});

it('responde el preflight del navegador para el endpoint de Google', function () {
    // `POST /api/auth/google` manda `Content-Type: application/json`, que NO es
    // simple: el navegador dispara un OPTIONS antes. Si ese preflight no
    // contesta, el login con Google no llega a intentarse siquiera.
    config(['cors.allowed_origins' => ['https://app.wajaycha.com']]);

    $this->call('OPTIONS', '/api/auth/google', server: [
        'HTTP_ORIGIN' => 'https://app.wajaycha.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])->assertNoContent(204)
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.wajaycha.com');
});
