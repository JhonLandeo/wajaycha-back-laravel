<?php

declare(strict_types=1);

use App\DTOs\Capture\CapturedMedia;
use App\Services\Capture\WhatsAppChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.whatsapp.access_token', 'token-de-prueba');
    config()->set('services.whatsapp.phone_id', '123456');

    $this->channel = app(WhatsAppChannel::class);
});

it('se identifica con la clave whatsapp', function () {
    expect($this->channel->key())->toBe('whatsapp');
});

it('descarga el medio resolviendo primero la URL en Meta', function () {
    Http::fake([
        'graph.facebook.com/v21.0/media-1' => Http::response([
            'url' => 'https://lookaside.fbsbx.com/archivo',
            'mime_type' => 'image/png',
        ]),
        'lookaside.fbsbx.com/*' => Http::response('bytes-del-comprobante'),
    ]);

    $media = $this->channel->fetchMedia('media-1');

    expect($media)->toBeInstanceOf(CapturedMedia::class)
        ->and($media->bytes)->toBe('bytes-del-comprobante')
        ->and($media->mimeType)->toBe('image/png');
});

it('devuelve null cuando Meta no resuelve el medio', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => 'not found'], 404),
    ]);

    expect($this->channel->fetchMedia('media-inexistente'))->toBeNull();
});

it('responde al remitente por el endpoint de mensajes de Meta', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
    ]);

    $this->channel->reply('51999888777', 'Comprobante registrado');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://graph.facebook.com/v21.0/123456/messages'
            && $request['to'] === '51999888777'
            && $request['text']['body'] === 'Comprobante registrado';
    });
});

it('no lanza cuando Meta rechaza el envio de la respuesta', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => 'rate limited'], 429),
    ]);

    // Un fallo de entrega se registra, no se propaga: la transaccion ya quedo guardada
    // y hacer fallar el job la duplicaria en el reintento.
    $this->channel->reply('51999888777', 'Comprobante registrado');

    Http::assertSentCount(1);
});
