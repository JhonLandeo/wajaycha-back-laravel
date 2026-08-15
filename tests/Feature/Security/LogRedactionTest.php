<?php

declare(strict_types=1);

/**
 * The application wrote amounts, merchant names, phone numbers, Telegram chat
 * ids and the user's own words about their spending into `Log::info`.
 *
 * Two things made that worse than a messy log file. `storage/logs` is plain
 * text with no retention rule, and `config/sentry.php` ships
 * `breadcrumbs.logs => env(..., true)`: every one of those lines rides along as
 * a breadcrumb on the next exception and leaves the server for a third party.
 * A tracker of someone's bank movements was narrating them to a vendor.
 *
 * The rule these cases pin is not "log less". It is that a log line may carry
 * an id, an outcome or a pseudonym, and may not carry a value someone could
 * read. Following the technique already used by
 * `VerifyTelegramSecretTokenTest`, which asserts the same thing about the
 * webhook secret.
 */

use App\Actions\Capture\RegisterCapturedTransactionAction;
use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Support\Redact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Every line the application logs, at any level.
 *
 * `Log::spy()` proves a single call was or was not made; this needs the opposite
 * shape — the whole transcript, so a value can be shown absent from all of it.
 *
 * Returns an `ArrayObject` and not an array, which is not a style choice. A
 * plain array is returned by value: the closure would keep appending to this
 * function's own copy and the caller would assert against an empty transcript
 * forever. Every `not->toContain` would pass, because a negative assertion over
 * nothing always does. That is precisely what happened on the first run here,
 * and only the positive counterweight case below exposed it.
 *
 * @return ArrayObject<int, string>
 */
function capturedLogLines(): ArrayObject
{
    /** @var ArrayObject<int, string> $lines */
    $lines = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($lines): void {
        $lines[] = $event->message;
    });

    return $lines;
}

/** @param ArrayObject<int, string> $lines */
function loggedTranscript(ArrayObject $lines): string
{
    return implode("\n", $lines->getArrayCopy());
}

/** The vector step is the only one that reaches the network. This keeps it home. */
function stubEmbeddingsWithNothing(): void
{
    app()->instance(EmbeddingService::class, new class extends EmbeddingService
    {
        public function generate(string $text): ?array
        {
            return null;
        }
    });
}

// ------------------------------------------------------------------- Redact

it('nunca deja el valor original adentro del seudonimo', function () {
    $telefono = '+51999888777';

    expect(Redact::id($telefono))
        ->not->toContain('999888777')
        ->not->toContain($telefono)
        ->toStartWith('<id: ')
        ->toEndWith('>');
});

it('marca con la misma forma el valor presente y el ausente', function () {
    // Antes el presente salia como '#a1b2c3d4e5' y el ausente como '<sin id>',
    // asi que una misma linea de log traia dos convenciones distintas. Se
    // unifican en la del helper de texto, que ya envolvia todo entre angulos.
    expect(Redact::id('51999888777'))->toStartWith('<')->toEndWith('>')
        ->and(Redact::id(null))->toBe('<sin id>')
        ->and(Redact::text('x'))->toStartWith('<')->toEndWith('>');
});

it('avisa en la linea cuando no hay clave, en vez de fingir un seudonimo', function () {
    config()->set('app.key', '');

    // hash_hmac con clave vacia no lanza: devuelve un digest perfectamente
    // valido. Sin este guard el log seguiria pareciendo seudonimizado sin
    // estarlo. Pero lanzar tampoco servia: la revision acotada corroboro que se
    // llevaba puesto el registro de usuarios, donde el User ya quedo creado.
    expect(Redact::id('51999888777'))->toBe('<id: sin clave>');
});

it('no rompe una ruta escrita para tragar cuando falta la clave', function () {
    config()->set('app.key', '');

    // El registro crea el User y despues vincula; si vincular lanzara, quedaria
    // una fila huerfana y un 500 sin token. Este es el caso que el throw rompia.
    $response = $this->postJson('/api/register', [
        'name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada-sin-clave@example.test',
        'whatsapp_phone' => '123',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertOk();
    expect(User::where('email', 'ada-sin-clave@example.test')->exists())->toBeTrue();
});

it('da siempre el mismo seudonimo para el mismo valor', function () {
    // Sin esto el seudonimo no sirve para nada: dos lineas del mismo usuario
    // tienen que poder juntarse.
    expect(Redact::id('51999888777'))->toBe(Redact::id('51999888777'))
        ->and(Redact::id('51999888777'))->not->toBe(Redact::id('51999888778'));
});

it('deriva el seudonimo de la clave de la aplicacion, no del valor solo', function () {
    $conUnaClave = Redact::id('51999888777');

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));

    // ESTE es el punto que no se puede simplificar a `sha256()`. El espacio de
    // los celulares peruanos es de unos 10^8 valores: un hash sin clave se
    // enumera entero en segundos y devuelve todos los numeros. Con HMAC hace
    // falta la clave, y la clave no vive en el archivo de log.
    expect(Redact::id('51999888777'))->not->toBe($conUnaClave);
});

it('reporta la forma del texto y nunca su contenido', function () {
    expect(Redact::text('almuerzo con mi vieja en Miraflores'))
        ->toBe('<texto: 35 caracteres>')
        ->not->toContain('Miraflores');

    expect(Redact::text(''))->toBe('<vacío>')
        ->and(Redact::text(null))->toBe('<nulo>');
});

// -------------------------------------------------------------- el recorrido

it('no escribe el comercio, el monto ni el mensaje del usuario al registrar una captura', function () {
    stubEmbeddingsWithNothing();

    $user = User::factory()->create();
    $lines = capturedLogLines();

    app(RegisterCapturedTransactionAction::class)->execute($user, new ParsedReceiptDTO(
        isValid: true,
        amount: 137.45,
        destination: 'BOTICA FARMAVIDA SJL',
        origin: 'Usuario',
        dateOperation: '2026-08-09 10:00:00',
        type: 'expense',
        message: 'medicinas para mi mama',
    ));

    $transcripcion = loggedTranscript($lines);

    // Las tres cosas que juntas reconstruyen el movimiento entero.
    expect($transcripcion)
        ->not->toContain('BOTICA FARMAVIDA SJL')
        ->not->toContain('FARMAVIDA')
        ->not->toContain('137.45')
        ->not->toContain('medicinas para mi mama');
});

it('sigue diciendo lo suficiente para seguir el rastro', function () {
    stubEmbeddingsWithNothing();

    $user = User::factory()->create();
    $lines = capturedLogLines();

    app(RegisterCapturedTransactionAction::class)->execute($user, new ParsedReceiptDTO(
        isValid: true,
        amount: 137.45,
        destination: 'BOTICA FARMAVIDA SJL',
        origin: 'Usuario',
        dateOperation: '2026-08-09 10:00:00',
        type: 'expense',
        message: 'medicinas para mi mama',
    ));

    $transcripcion = loggedTranscript($lines);

    // El contrapeso del caso anterior. Redactar hasta que el log no sirva para
    // diagnosticar nada tambien es un defecto, solo que uno que nadie reporta.
    expect($transcripcion)
        ->toContain("usuario {$user->id}")
        ->toContain('Entity Resolution')
        ->toContain('Captura registrada');
});

it('no escribe el chat id de Telegram cuando el remitente no esta vinculado', function () {
    // La rama "sin vincular" contesta por sendMessage antes de volver, asi que sin
    // esto el caso salia a api.telegram.org de verdad, con el bot token de
    // produccion. Verificado bloqueando la salida de red: fallaba con
    // ConnectionException en TelegramChannel::reply(). Es la misma convencion que
    // ya sigue ProcessTelegramCaptureTest para este mismo job.
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $chatId = '987654321';
    $lines = capturedLogLines();

    (new App\Jobs\ProcessTelegramCapture($chatId, 'gaste 40 soles'))->handle(
        app(App\Services\Capture\CaptureChannelRegistry::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Capture\ChannelLinkTokenRedeemer::class),
        app(App\Services\AI\GeminiVisionService::class),
        app(App\Services\AI\GeminiTextService::class),
        app(RegisterCapturedTransactionAction::class),
    );

    $transcripcion = loggedTranscript($lines);

    // Un chat id no es anonimo: es con lo que se le escribe a esa persona.
    expect($transcripcion)->not->toContain($chatId)
        ->and($transcripcion)->toContain(Redact::id($chatId));
});

/**
 * The credential cases below are a different failure from the ones above.
 *
 * Everything so far protects the user's data. What follows protects the
 * application's own keys, and the leak is not something anyone wrote: the
 * Telegram Bot API puts the bot token in the URL path and Gemini puts its key
 * in the query string, so when an outbound call cannot complete at all, the
 * `ConnectionException` Guzzle raises carries the full request URI in its
 * message. Every `Log::error('...: '.$e->getMessage())` then writes a live
 * credential to `storage/logs` and ships it to Sentry as a breadcrumb.
 */
it('no escribe el bot token cuando no se puede contactar a Telegram para responder', function () {
    config()->set('services.telegram.bot_token', '123456:BOT-TOKEN-DE-PRODUCCION');

    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException(
        'cURL error 6: Could not resolve host (see https://curl.se/libcurl/c/libcurl-errors.html) '
        .'for https://api.telegram.org/bot123456:BOT-TOKEN-DE-PRODUCCION/sendMessage'
    ));

    $lines = capturedLogLines();

    // No debe lanzar: la transaccion ya estaria guardada y hacer fallar el job
    // la duplicaria en el reintento.
    app(App\Services\Capture\TelegramChannel::class)->reply('123456789', 'Registrado');

    $transcripcion = loggedTranscript($lines);

    expect($transcripcion)->not->toContain('BOT-TOKEN-DE-PRODUCCION')
        ->and($transcripcion)->toContain('<secreto>');
});

it('no escribe el bot token cuando no se puede contactar a Telegram para bajar un archivo', function () {
    config()->set('services.telegram.bot_token', '123456:BOT-TOKEN-DE-PRODUCCION');

    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException(
        'cURL error 28: Operation timed out for https://api.telegram.org/bot123456:BOT-TOKEN-DE-PRODUCCION/getFile?file_id=abc'
    ));

    $lines = capturedLogLines();

    $media = app(App\Services\Capture\TelegramChannel::class)->fetchMedia('abc');

    expect($media)->toBeNull()
        ->and(loggedTranscript($lines))->not->toContain('BOT-TOKEN-DE-PRODUCCION');
});

it('tapa el token de un bot que ya no es el configurado', function () {
    config()->set('services.telegram.bot_token', 'el-que-si-esta-configurado');

    // Durante una rotacion la excepcion puede traer el token viejo, que ya no
    // esta en config y por lo tanto no se puede tapar por valor. El patron de la
    // URL de Telegram lo cubre igual.
    $saneado = Redact::secrets('for https://api.telegram.org/bot987654:TOKEN-VIEJO_x-y/sendMessage');

    expect($saneado)->not->toContain('TOKEN-VIEJO')
        ->and($saneado)->toContain('<secreto>');
});

it('tapa la api key de Gemini que viaja en el query string', function () {
    config()->set('services.gemini.api_key', 'AIzaSyClaveDeProduccion');

    $saneado = Redact::secrets(
        'cURL error 28 for https://generativelanguage.googleapis.com/v1/models:generateContent?key=AIzaSyClaveDeProduccion'
    );

    expect($saneado)->not->toContain('AIzaSyClaveDeProduccion');
});

it('deja intacto un mensaje que no trae ningun secreto', function () {
    config()->set('services.telegram.bot_token', '123456:BOT-TOKEN-DE-PRODUCCION');

    // El contrapeso: sin este caso, un metodo que devolviera '<secreto>' siempre
    // pasaria todas las afirmaciones negativas de arriba.
    expect(Redact::secrets('cURL error 28: Operation timed out'))
        ->toBe('cURL error 28: Operation timed out');
});

it('no confunde un valor de configuracion vacio con un secreto', function () {
    config()->set('services.telegram.bot_token', '');
    config()->set('services.whatsapp.access_token', '');

    // Un secreto sin configurar es cadena vacia. Reemplazar la cadena vacia
    // insertaria el marcador entre cada caracter de la linea.
    expect(Redact::secrets('un mensaje cualquiera'))->toBe('un mensaje cualquiera');
});
