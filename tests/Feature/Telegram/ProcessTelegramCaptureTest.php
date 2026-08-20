<?php

declare(strict_types=1);

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Jobs\ProcessTelegramCapture;
use App\Models\ChannelIdentity;
use App\Models\ChannelLinkToken;
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
    bool $unsupported = false,
): void {
    $vision = Mockery::mock(GeminiVisionService::class);
    $vision->shouldReceive('parseReceipt')->andReturn($parsed);

    $textService = Mockery::mock(GeminiTextService::class);
    $textService->shouldReceive('parseText')->andReturn($parsed);

    (new ProcessTelegramCapture($chatId, $text, $mediaReference, $unsupported))->handle(
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

    // El job recibe el digest, no el token: el controlador lo reemplaza antes de
    // despachar para que el credencial no quede en el payload de la cola.
    runTelegramJob('123456789', '/start '.ChannelLinkTokenRedeemer::hash($token));

    expect(app(ChannelIdentityResolver::class)->resolve('telegram', '123456789')?->id)->toBe($user->id)
        ->and(lastTelegramReply())->toContain('Cuenta vinculada');
});

it('responde lo mismo ante un /start con token invalido, vencido o ya usado', function () {
    $user = User::factory()->create();
    $token = app(ChannelLinkTokenIssuer::class)->issue($user)->token;

    $hash = ChannelLinkTokenRedeemer::hash($token);

    runTelegramJob('111', "/start {$hash}");
    $tokenUsado = lastTelegramReply();

    runTelegramJob('222', "/start {$hash}");
    $yaGastado = lastTelegramReply();

    runTelegramJob('333', '/start '.ChannelLinkTokenRedeemer::hash('token-inventado'));
    $inventado = lastTelegramReply();

    // El exito se distingue; los rechazos entre si, no.
    expect($tokenUsado)->toContain('Cuenta vinculada')
        ->and($yaGastado)->toBe($inventado)
        ->and($yaGastado)->toContain('no es válido o ya fue usado');
});

it('le dice al remitente ya vinculado que no necesita otro enlace', function () {
    $user = telegramSender();

    // Un token perfectamente valido de otro usuario: el rechazo viene de la
    // identidad del remitente, no del token, y por eso "genera uno nuevo" nunca
    // lo hubiera sacado del bucle.
    $token = app(ChannelLinkTokenIssuer::class)->issue(User::factory()->create())->token;

    runTelegramJob('123456789', '/start '.ChannelLinkTokenRedeemer::hash($token));

    expect(lastTelegramReply())->toContain('ya está vinculada')
        ->and(lastTelegramReply())->not->toContain('Genera uno nuevo')
        // El chat sigue siendo del mismo dueño y el token del otro usuario
        // sigue sin gastarse: solo cambio lo que se responde.
        ->and(app(ChannelIdentityResolver::class)->resolve('telegram', '123456789')?->id)->toBe($user->id)
        ->and(ChannelIdentity::where('external_id', '123456789')->count())->toBe(1)
        ->and(ChannelLinkToken::sole()->redeemed_at)->toBeNull();
});

it('no revela nada nuevo a un remitente sin vincular', function () {
    // El mismo token invalido desde un chat sin dueño sigue recibiendo la
    // respuesta generica: la rama nueva solo alcanza a quien ya esta vinculado.
    runTelegramJob('999999999', '/start '.ChannelLinkTokenRedeemer::hash('token-inventado'));

    expect(lastTelegramReply())->toContain('no es válido o ya fue usado');
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
    // comodin, que responde ok sin result.file_path. Telegram, para un archivo que no
    // existe, responde ok:false —eso lo cubre TelegramChannelTest—; esta es la otra
    // rama: una respuesta exitosa a la que le falta el campo que necesitamos.
    config()->set('services.telegram.bot_token', 'TOKEN-SIN-STUB');

    runTelegramJob('123456789', null, 'file-inexistente', CaptureFixtures::validMovement());

    // Bajar el archivo y leerlo son dos fallos distintos y se dicen distinto.
    // "No pude leer ese envio" ante una descarga fallida le echaba la culpa a la
    // foto del usuario por una caida nuestra, y el usuario respondia sacando la
    // foto otra vez — que falla igual mientras dure la caida.
    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('No pude descargar tu comprobante')
        ->and(lastTelegramReply())->toContain('problema nuestro');
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

it('le avisa al remitente cuando el tipo de mensaje no se puede leer', function () {
    telegramSender();

    // Una nota de voz, un sticker, un documento. El controlador ya decidio que no
    // hay nada que parsear; esto llega a la cola solo para que el remitente no se
    // quede en silencio, porque la entrega ya quedo reclamada y Telegram no la
    // reenvia. Todas las demas ramas de fallo contestan; el silencio se leia como
    // que el bot estaba caido.
    runTelegramJob('123456789', null, null, null, true);

    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('Todavía no puedo leer ese tipo de mensaje');
});

it('no le pide a Gemini que parsee un mensaje que ya se sabe ilegible', function () {
    telegramSender();

    $vision = Mockery::mock(GeminiVisionService::class);
    $vision->shouldNotReceive('parseReceipt');

    $textService = Mockery::mock(GeminiTextService::class);
    $textService->shouldNotReceive('parseText');

    // Una llamada a Gemini cuesta dinero real. La rama de aviso corta antes.
    (new ProcessTelegramCapture('123456789', null, null, true))->handle(
        app(CaptureChannelRegistry::class),
        app(ChannelIdentityResolver::class),
        app(ChannelLinkTokenRedeemer::class),
        $vision,
        $textService,
        app(App\Actions\Capture\RegisterCapturedTransactionAction::class),
    );

    expect(lastTelegramReply())->toContain('Todavía no puedo leer');
});

it('rechaza un movimiento valido que vino sin monto, en vez de romper contra la base', function () {
    telegramSender();

    // `isValid` y `amount` los arma el DTO por separado desde la respuesta de
    // Gemini, y nada los ata: el acoplamiento vive solo como una frase en el
    // prompt. Sin este control el null llegaba a una columna NOT NULL, el job
    // explotaba y el usuario recibia el mensaje generico de error inesperado en
    // lugar del que describe lo que realmente paso.
    $sinMonto = new ParsedReceiptDTO(
        isValid: true,
        amount: null,
        destination: 'Mercado Central',
        origin: 'Yape',
        dateOperation: '2026-08-09',
        type: 'expense',
        message: null,
    );

    runTelegramJob('123456789', 'gaste no se cuanto', null, $sinMonto);

    expect(Transaction::count())->toBe(0)
        ->and(lastTelegramReply())->toContain('no parece un movimiento con monto');
});

// ------------------------------------------------------------------- ingresos

/**
 * The income half of the capture channel.
 *
 * It was written months ago — `GeminiTextService` teaches Gemini that "me
 * pagaron" is an `income`, `RegisterCapturedTransactionAction` persists the
 * type, and the confirmation has its own wording — and until now not one test
 * asserted it. The bot's own copy never mentioned it either, so nobody, the
 * owner included, knew the channel took income at all.
 */

it('registra un ingreso con su tipo, no como gasto', function () {
    $user = telegramSender();

    runTelegramJob('123456789', 'me pagaron 800 del estudio', null, CaptureFixtures::receivedMovement());

    $transaction = Transaction::sole();

    expect($transaction->user_id)->toBe($user->id)
        ->and($transaction->type_transaction)->toBe('income')
        ->and((float) $transaction->amount)->toBe(800.00);
});

it('confirma un ingreso diciendo de quien se recibio, no a quien se pago', function () {
    telegramSender();

    runTelegramJob('123456789', 'me pagaron 800 del estudio', null, CaptureFixtures::receivedMovement());

    expect(lastTelegramReply())->toContain('recibido de')
        ->and(lastTelegramReply())->toContain('Estudio Contable Vega')
        ->and(lastTelegramReply())->not->toContain('pagado a');
});

it('describe el ingreso por su origen y no lo llama Desconocido', function () {
    telegramSender();

    runTelegramJob('123456789', 'me pagaron 800', null, CaptureFixtures::receivedMovement());

    // `origin`, no `destination`: leerlo del lado equivocado deja la descripcion
    // vacia y la accion cae al relleno 'Desconocido WhatsApp', que agrupa todos
    // los ingresos de todos los pagadores bajo un mismo Detail.
    expect(Transaction::sole()->detail->description)->not->toContain('Desconocido');
});

it('no coachea un ingreso, que no tiene presupuesto que rebasar', function () {
    telegramSender();

    $coach = Mockery::mock(App\Services\Coaching\FinancialCoachingService::class);
    $coach->shouldNotReceive('speak');
    app()->instance(App\Services\Coaching\FinancialCoachingService::class, $coach);

    runTelegramJob('123456789', 'me pagaron 800', null, CaptureFixtures::receivedMovement());

    expect(Transaction::sole()->type_transaction)->toBe('income');
});

it('anuncia las dos direcciones al vincular la cuenta', function () {
    $user = User::factory()->create();
    $token = app(ChannelLinkTokenIssuer::class)->issue($user)->token;

    runTelegramJob('123456789', '/start '.ChannelLinkTokenRedeemer::hash($token));

    // El canal registra ingresos desde que existe y ninguna linea de copy lo
    // decia, asi que nadie los mandaba. Este caso existe para que el mensaje de
    // bienvenida no vuelva a hablar solo de gastos.
    expect(lastTelegramReply())->toContain('me pagaron');
});
