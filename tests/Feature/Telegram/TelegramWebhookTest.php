<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyTelegramSecretToken;
use App\Jobs\ProcessTelegramCapture;
use App\Models\ProcessedChannelUpdate;
use App\Services\Capture\TelegramChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.telegram.secret_token', 'secreto-compartido');
    Queue::fake();
});

function postUpdate(array $body, ?string $secret = 'secreto-compartido')
{
    $headers = $secret === null ? [] : [VerifyTelegramSecretToken::HEADER => $secret];

    return test()->postJson('/api/telegram/webhook', $body, $headers);
}

function textUpdate(int $updateId, string $text, string $chatId = '123456789'): array
{
    return [
        'update_id' => $updateId,
        'message' => ['chat' => ['id' => (int) $chatId], 'text' => $text],
    ];
}

it('encola la captura de un mensaje de texto', function () {
    postUpdate(textUpdate(1, 'gaste 20 en el taxi'))->assertOk();

    Queue::assertPushed(ProcessTelegramCapture::class, 1);
    expect(ProcessedChannelUpdate::count())->toBe(1);
});

it('encola la captura de una foto', function () {
    postUpdate([
        'update_id' => 2,
        'message' => [
            'chat' => ['id' => 123456789],
            'photo' => [['file_id' => 'chica', 'file_size' => 100], ['file_id' => 'grande', 'file_size' => 9000]],
        ],
    ])->assertOk();

    Queue::assertPushed(ProcessTelegramCapture::class, 1);
});

it('rechaza la entrega sin el secreto y no encola ni recuerda nada', function () {
    postUpdate(textUpdate(3, 'gaste 20'), null)->assertForbidden();

    // Rechazado en el borde: ni job, ni fila de deduplicacion, ni respuesta.
    Queue::assertNothingPushed();
    expect(ProcessedChannelUpdate::count())->toBe(0);
});

it('rechaza la entrega con un secreto equivocado', function () {
    postUpdate(textUpdate(4, 'gaste 20'), 'secreto-falso')->assertForbidden();

    Queue::assertNothingPushed();
});

it('descarta la reentrega del mismo update sin encolar de nuevo', function () {
    $update = textUpdate(5, 'gaste 20 en el taxi');

    postUpdate($update)->assertOk();
    postUpdate($update)->assertOk();

    // Telegram reintenta hasta recibir 200; la segunda no puede costar otra llamada
    // a Gemini ni crear una segunda transaccion.
    Queue::assertPushed(ProcessTelegramCapture::class, 1);
    expect(ProcessedChannelUpdate::count())->toBe(1);
});

it('procesa todos los updates de una entrega, no solo el primero', function () {
    postUpdate([
        textUpdate(10, 'gaste 10'),
        textUpdate(11, 'gaste 11'),
        textUpdate(12, 'gaste 12'),
    ])->assertOk();

    Queue::assertPushed(ProcessTelegramCapture::class, 3);
    expect(ProcessedChannelUpdate::count())->toBe(3);
});

it('sigue con el resto cuando un update de la tanda no es utilizable', function () {
    postUpdate([
        textUpdate(20, 'gaste 20'),
        ['update_id' => 21, 'message' => ['chat' => ['id' => 123456789], 'sticker' => ['file_id' => 'x']]],
        textUpdate(22, 'gaste 22'),
    ])->assertOk();

    // Dos encolados, tres recordados: el no soportado tambien se marca, porque
    // reintentarlo no lo va a volver utilizable.
    Queue::assertPushed(ProcessTelegramCapture::class, 2);
    expect(ProcessedChannelUpdate::count())->toBe(3);
});

it('responde 200 ante un update sin mensaje, en vez de invitar al reintento', function () {
    postUpdate(['update_id' => 30])->assertOk();

    Queue::assertNothingPushed();
    expect(ProcessedChannelUpdate::count())->toBe(1);
});

it('responde 200 ante un cuerpo que no tiene forma de update', function () {
    postUpdate(['cualquier' => 'cosa'])->assertOk();

    Queue::assertNothingPushed();
    expect(ProcessedChannelUpdate::count())->toBe(0);
});

it('pasa el texto y el chat id tal como llegaron', function () {
    postUpdate(textUpdate(40, '/start un-token', '987654321'))->assertOk();

    Queue::assertPushed(function (ProcessTelegramCapture $job) {
        $reflection = new ReflectionClass($job);

        $chatId = $reflection->getProperty('chatId');
        $text = $reflection->getProperty('text');

        return $chatId->getValue($job) === '987654321'
            && $text->getValue($job) === '/start un-token';
    });
});

it('descarta un update_id mas largo que la columna sin devolver 500', function () {
    postUpdate([
        'update_id' => str_repeat('9', 200),
        'message' => ['chat' => ['id' => 123456789], 'text' => 'gaste 20'],
    ])->assertOk();

    // Telegram manda un entero; algo asi solo viene de un cuerpo forjado. No puede
    // romper el contrato de responder siempre 200.
    Queue::assertNothingPushed();
    expect(ProcessedChannelUpdate::count())->toBe(0);
});

it('acota cuantos updates procesa de una sola entrega', function () {
    $updates = [];
    for ($i = 1; $i <= 105; $i++) {
        $updates[] = textUpdate($i, "gaste {$i}");
    }

    postUpdate($updates)->assertOk();

    // El tope existe para que un cuerpo forjado no dispare trabajo sin limite, y lo
    // descartado queda registrado en vez de desaparecer en silencio.
    Queue::assertPushed(ProcessTelegramCapture::class, 100);
    expect(ProcessedChannelUpdate::count())->toBe(100);
});

it('no encola nada cuando ninguna variante de la foto entra en el limite', function () {
    postUpdate([
        'update_id' => 50,
        'message' => [
            'chat' => ['id' => 123456789],
            'photo' => [['file_id' => 'enorme', 'file_size' => TelegramChannel::MAX_PHOTO_BYTES + 1]],
        ],
    ])->assertOk();

    // Elegir la variante es conocimiento de Telegram y vive en este borde, asi que
    // una foto inservible ni siquiera llega a la cola.
    Queue::assertNothingPushed();
    expect(ProcessedChannelUpdate::count())->toBe(1);
});

it('encola el file id ya elegido, no el arreglo de variantes', function () {
    postUpdate([
        'update_id' => 51,
        'message' => [
            'chat' => ['id' => 123456789],
            'photo' => [
                ['file_id' => 'miniatura', 'file_size' => 1_000],
                ['file_id' => 'grande', 'file_size' => 800_000],
            ],
        ],
    ])->assertOk();

    Queue::assertPushed(function (ProcessTelegramCapture $job) {
        $prop = (new ReflectionClass($job))->getProperty('mediaReference');

        return $prop->getValue($job) === 'grande';
    });
});
