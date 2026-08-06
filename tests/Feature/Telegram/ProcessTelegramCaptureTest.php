<?php

declare(strict_types=1);

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Jobs\ProcessTelegramCapture;
use App\Models\ChannelIdentity;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\GeminiTextService;
use App\Services\AI\GeminiVisionService;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\ChannelLinkTokenIssuer;
use App\Services\Capture\ChannelLinkTokenRedeemer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CaptureFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'BOT-TOKEN');
    config()->set('services.telegram.bot_username', 'wajaycha_bot');

    // Se fakea el transporte, no el puerto: asi el job ejercita al TelegramChannel
    // real, incluida la eleccion de variante y las dos llamadas de fetchMedia.
    Http::fake([
        'api.telegram.org/botBOT-TOKEN/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'photos/file_9.jpg'],
        ]),
        'api.telegram.org/file/*' => Http::response('bytes-del-comprobante'),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);
});

function telegramSender(string $chatId = '123456789'): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => $chatId,
    ]);

    return $user;
}

function runTelegramJob(
    string $chatId,
    ?string $text = null,
    ?string $mediaReference = null,
    ?ParsedReceiptDTO $parsed = null,
): void {
    $vision = Mockery::mock(GeminiVisionService::class);
    $vision->shouldReceive('parseReceipt')->andReturn($parsed);

    $textService = Mockery::mock(GeminiTextService::class);
    $textService->shouldReceive('parseText')->andReturn($parsed);

    (new ProcessTelegramCapture($chatId, $text, $mediaReference))->handle(
        app(CaptureChannelRegistry::class),
        app(ChannelIdentityResolver::class),
        app(ChannelLinkTokenRedeemer::class),
        $vision,
        $textService,
        app(App\Actions\Capture\RegisterCapturedTransactionAction::class),
    );
}

function lastTelegramReply(): ?string
{
    $enviados = collect(Http::recorded())
        ->filter(fn ($par) => str_contains($par[0]->url(), '/sendMessage'));

    return $enviados->isEmpty() ? null : $enviados->last()[0]['text'];
}

it('registra la transaccion de un remitente vinculado que manda texto', function () {
    $user = telegramSender();

    runTelegramJob('123456789', 'gaste 25.50 en la bodega', null, CaptureFixtures::validMovement());

    expect(Transaction::sole()->user_id)->toBe($user->id)
        ->and(lastTelegramReply())->toContain('Registrado')
        ->and(lastTelegramReply())->toContain('25.50');
});

it('registra la transaccion de una foto por su file id', function () {
    $user = telegramSender();

    runTelegramJob('123456789', null, 'grande', CaptureFixtures::validMovement());

    expect(Transaction::sole()->user_id)->toBe($user->id)
        ->and(lastTelegramReply())->toContain('Registrado');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'getFile')
        && str_contains($r->url(), 'grande'));
});

it('no registra nada y explica como vincular cuando el chat es desconocido', function () {
    runTelegramJob('999999999', 'gaste 20 en el taxi', null, CaptureFixtures::validMovement());

    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('no está vinculada');
});

it('atribuye la transaccion al dueño del chat, no a cualquier usuario', function () {
    $owner = telegramSender();
    $otro = User::factory()->create();

    runTelegramJob('123456789', 'gaste 25.50', null, CaptureFixtures::validMovement());

    expect(Transaction::sole()->user_id)->toBe($owner->id)
        ->and(Transaction::where('user_id', $otro->id)->count())->toBe(0);
});

it('avisa y no registra cuando la IA no responde', function () {
    telegramSender();

    runTelegramJob('123456789', 'gaste algo', null, null);

    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('No pude leer');
});

it('avisa y no registra cuando el mensaje no describe un movimiento', function () {
    telegramSender();

    runTelegramJob('123456789', 'hola que tal', null, CaptureFixtures::unparseableMovement());

    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('no parece un movimiento');
});

it('vincula la cuenta cuando llega /start con un token valido', function () {
    $user = User::factory()->create();
    $token = app(ChannelLinkTokenIssuer::class)->issue($user)->token;

    runTelegramJob('123456789', "/start {$token}");

    expect(app(ChannelIdentityResolver::class)->resolve('telegram', '123456789')?->id)->toBe($user->id)
        ->and(lastTelegramReply())->toContain('Cuenta vinculada');
});

it('responde lo mismo ante un /start con token invalido, vencido o ya usado', function () {
    $user = User::factory()->create();
    $token = app(ChannelLinkTokenIssuer::class)->issue($user)->token;

    runTelegramJob('111', "/start {$token}");
    $tokenUsado = lastTelegramReply();

    runTelegramJob('222', "/start {$token}");
    $yaGastado = lastTelegramReply();

    runTelegramJob('333', '/start token-inventado');
    $inventado = lastTelegramReply();

    // El exito se distingue; los rechazos entre si, no.
    expect($tokenUsado)->toContain('Cuenta vinculada')
        ->and($yaGastado)->toBe($inventado)
        ->and($yaGastado)->toContain('no es válido o ya fue usado');
});

it('no intenta parsear un /start como si fuera un movimiento', function () {
    telegramSender();

    runTelegramJob('123456789', '/start token-inventado', null, CaptureFixtures::validMovement());

    // Aunque la IA devolveria un movimiento valido, /start nunca llega ahi.
    expect(Transaction::count())->toBe(0);
});

it('avisa al remitente cuando el job falla inesperadamente', function () {
    (new ProcessTelegramCapture('123456789', 'gaste algo'))
        ->failed(new RuntimeException('la cola exploto'));

    expect(lastTelegramReply())->toContain('error inesperado');
});

it('avisa y no registra cuando el medio de la foto no se puede descargar', function () {
    telegramSender();

    // Http::fake() acumula stubs en vez de reemplazarlos, asi que el del beforeEach
    // seguiria ganando. Se cambia el token para que la URL de getFile caiga en el
    // comodin, que responde ok sin result.file_path: exactamente lo que Telegram
    // devuelve para un archivo inexistente.
    config()->set('services.telegram.bot_token', 'TOKEN-SIN-STUB');

    runTelegramJob('123456789', null, 'file-inexistente', CaptureFixtures::validMovement());

    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('No pude leer');
});

it('avisa y no registra cuando la IA no lee la foto', function () {
    telegramSender();

    runTelegramJob('123456789', null, 'file-9', null);

    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('No pude leer');
});

it('avisa y no registra cuando la foto no es un comprobante', function () {
    telegramSender();

    runTelegramJob('123456789', null, 'file-9', CaptureFixtures::unparseableMovement());

    // La foto se descargo bien y Gemini la leyo: simplemente no era un movimiento.
    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('no parece un movimiento');
});
