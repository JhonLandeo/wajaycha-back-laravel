<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyTelegramSecretToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

function runSecretTokenMiddleware(?string $header): Illuminate\Http\JsonResponse|Illuminate\Http\Response
{
    $request = Request::create('/api/telegram/webhook', 'POST');

    if ($header !== null) {
        $request->headers->set(VerifyTelegramSecretToken::HEADER, $header);
    }

    return (new VerifyTelegramSecretToken)->handle(
        $request,
        fn () => response('LLEGO_AL_CONTROLADOR', 200)
    );
}

beforeEach(function () {
    config()->set('services.telegram.secret_token', 'secreto-compartido');
});

it('deja pasar la entrega que trae el secreto correcto', function () {
    $response = runSecretTokenMiddleware('secreto-compartido');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('LLEGO_AL_CONTROLADOR');
});

it('rechaza la entrega con un secreto distinto', function () {
    $response = runSecretTokenMiddleware('secreto-equivocado');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->not->toContain('LLEGO_AL_CONTROLADOR');
});

it('rechaza la entrega que no trae el header', function () {
    expect(runSecretTokenMiddleware(null)->getStatusCode())->toBe(403);
});

it('rechaza la entrega con el header vacio', function () {
    expect(runSecretTokenMiddleware('')->getStatusCode())->toBe(403);
});

it('cierra el endpoint cuando el secreto no esta configurado, en vez de abrirlo', function () {
    config()->set('services.telegram.secret_token', null);

    // Falla cerrado: una config faltante no puede convertir un webhook publico en
    // un buzon abierto donde cualquiera inventa transacciones ajenas.
    expect(runSecretTokenMiddleware('lo-que-sea')->getStatusCode())->toBe(403)
        ->and(runSecretTokenMiddleware(null)->getStatusCode())->toBe(403);
});

it('distingue en el log la falta de configuracion de un intento invalido', function () {
    Log::spy();

    config()->set('services.telegram.secret_token', null);
    runSecretTokenMiddleware('lo-que-sea');

    // error, no warning: esto lo tiene que arreglar un operador, no es trafico hostil.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $m) => str_contains($m, 'secret_token'))
        ->once();
    Log::shouldNotHaveReceived('warning');
});

it('registra como warning el intento con secreto invalido', function () {
    Log::spy();

    runSecretTokenMiddleware('secreto-equivocado');

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');
});

it('nunca escribe el secreto esperado ni el recibido en el log', function () {
    Log::spy();

    runSecretTokenMiddleware('intento-de-adivinanza');

    Log::shouldNotHaveReceived('warning', fn (string $m) => str_contains($m, 'secreto-compartido')
        || str_contains($m, 'intento-de-adivinanza'));
});
