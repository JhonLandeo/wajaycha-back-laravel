<?php

declare(strict_types=1);

use App\DTOs\Capture\CapturedMedia;
use App\Services\Capture\CaptureChannel;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\TelegramChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'BOT-TOKEN');

    $this->channel = app(TelegramChannel::class);
});

it('se identifica con la clave telegram', function () {
    expect($this->channel)->toBeInstanceOf(CaptureChannel::class)
        ->and($this->channel->key())->toBe('telegram');
});

it('queda registrado junto al adaptador de WhatsApp', function () {
    $registry = app(CaptureChannelRegistry::class);

    expect($registry->for('telegram'))->toBeInstanceOf(TelegramChannel::class)
        ->and($registry->keys())->toContain('whatsapp', 'telegram');
});

it('descarga el archivo resolviendo primero su ruta con getFile', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'photos/file_9.png'],
        ]),
        'api.telegram.org/file/botBOT-TOKEN/*' => Http::response('bytes-del-comprobante'),
    ]);

    $media = $this->channel->fetchMedia('file-9');

    expect($media)->toBeInstanceOf(CapturedMedia::class)
        ->and($media->bytes)->toBe('bytes-del-comprobante')
        ->and($media->mimeType)->toBe('image/png');
});

it('cae a image/jpeg cuando la extension no dice nada', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'photos/sin_extension'],
        ]),
        'api.telegram.org/file/*' => Http::response('bytes'),
    ]);

    expect($this->channel->fetchMedia('file-9')->mimeType)->toBe('image/jpeg');
});

it('devuelve null cuando getFile no resuelve la ruta', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response(['ok' => false], 400),
    ]);

    expect($this->channel->fetchMedia('file-inexistente'))->toBeNull();
});

it('devuelve null cuando la descarga responde 200 con el cuerpo vacio', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'photos/file_9.jpg'],
        ]),
        'api.telegram.org/file/*' => Http::response(''),
    ]);

    // Cero bytes con 200 es un fallo de transporte disfrazado. Mandarlos a Gemini
    // gasta una llamada para no obtener nada.
    expect($this->channel->fetchMedia('file-9'))->toBeNull();
});

it('devuelve null cuando getFile responde 200 pero con ok false', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response([
            'ok' => false,
            'description' => 'file not found',
        ]),
    ]);

    // Telegram contesta 200 con ok:false: el status no alcanza como senal.
    expect($this->channel->fetchMedia('file-inexistente'))->toBeNull();
});

it('devuelve null cuando la descarga falla con un status de error', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'photos/file_9.jpg'],
        ]),
        'api.telegram.org/file/*' => Http::response('', 500),
    ]);

    expect($this->channel->fetchMedia('file-9'))->toBeNull();
});

it('responde al remitente por sendMessage', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->channel->reply('123456789', 'Comprobante registrado');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telegram.org/botBOT-TOKEN/sendMessage'
        && $r['chat_id'] === '123456789'
        && $r['text'] === 'Comprobante registrado');
});

it('no lanza cuando Telegram rechaza la respuesta', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 429)]);

    $this->channel->reply('123456789', 'Comprobante registrado');

    // Antes este caso afirmaba `assertSentCount(1)`, o sea "no reintentamos".
    // Ahora reintenta una vez: 429 es transitorio por definicion. La assertion
    // se cambio a proposito y no por conveniencia — la parte que importa sigue
    // siendo la misma y esta arriba: llegar aca sin excepcion. La transaccion ya
    // esta guardada y hacer fallar el job la duplicaria en el reintento.
    Http::assertSentCount(2);
});

it('reintenta una sola vez el envio, porque sendMessage no es idempotente', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);

    $this->channel->reply('123456789', 'Comprobante registrado');

    // Dos y no tres: si Telegram acepto el mensaje y se perdio la respuesta,
    // cada reintento manda un "✅ Registrado" duplicado al usuario.
    Http::assertSentCount(2);
});

it('no reintenta un rechazo definitivo del envio', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);

    $this->channel->reply('123456789', 'Comprobante registrado');

    // Un 400 no mejora insistiendo: el pedido estaba mal y va a seguir mal.
    Http::assertSentCount(1);
});

it('reintenta la descarga cuando Telegram devuelve un error transitorio', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'photos/file_9.png'],
        ]),
        'api.telegram.org/file/*' => Http::sequence()
            ->push('', 503)
            ->push('bytes-del-comprobante', 200),
    ]);

    // Sin reintento este comprobante se perdia y el usuario recibia
    // "no pude leer ese envio" por un 503 que duro un segundo.
    expect($this->channel->fetchMedia('file-9')?->bytes)->toBe('bytes-del-comprobante');
});

it('no reintenta cuando getFile responde que el archivo no existe', function () {
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response(['ok' => false], 404),
    ]);

    expect($this->channel->fetchMedia('file-inexistente'))->toBeNull();

    Http::assertSentCount(1);
});

it('elige la variante mas grande dentro del limite', function () {
    $elegida = $this->channel->largestPhotoUnder([
        ['file_id' => 'miniatura', 'file_size' => 1_000],
        ['file_id' => 'grande', 'file_size' => 900_000],
        ['file_id' => 'media', 'file_size' => 50_000],
    ]);

    // Una miniatura parsea sin error y da una lectura peor en silencio: es la falla
    // que nadie nota, y por eso se elige explicitamente la mayor.
    expect($elegida)->toBe('grande');
});

it('descarta las variantes que exceden el limite', function () {
    $elegida = $this->channel->largestPhotoUnder([
        ['file_id' => 'aceptable', 'file_size' => 500_000],
        ['file_id' => 'enorme', 'file_size' => TelegramChannel::MAX_PHOTO_BYTES + 1],
    ]);

    expect($elegida)->toBe('aceptable');
});

it('acepta la variante que pesa exactamente el limite', function () {
    $elegida = $this->channel->largestPhotoUnder([
        ['file_id' => 'justo', 'file_size' => TelegramChannel::MAX_PHOTO_BYTES],
    ]);

    expect($elegida)->toBe('justo');
});

it('devuelve null cuando ninguna variante entra en el limite', function () {
    expect($this->channel->largestPhotoUnder([
        ['file_id' => 'enorme', 'file_size' => TelegramChannel::MAX_PHOTO_BYTES + 1],
    ]))->toBeNull();
});

it('devuelve null cuando no hay variantes', function () {
    expect($this->channel->largestPhotoUnder([]))->toBeNull();
});

it('tolera una variante sin file_size en lugar de romper', function () {
    // Telegram siempre manda file_size, pero un payload inesperado no puede tumbar
    // la captura: se trata como cero y compite como la mas chica.
    $elegida = $this->channel->largestPhotoUnder([
        ['file_id' => 'sin_tamano'],
        ['file_id' => 'con_tamano', 'file_size' => 10_000],
    ]);

    expect($elegida)->toBe('con_tamano');
});
