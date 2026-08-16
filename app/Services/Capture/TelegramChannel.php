<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DTOs\Capture\CapturedMedia;
use App\Support\OutboundHttp;
use App\Support\Redact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Telegram as the second implementation of the capture port.
 *
 * Everything downstream — Gemini, ParsedReceiptDTO, Entity Resolution,
 * Categorisation — is unchanged. Only the transport is new.
 */
class TelegramChannel implements CaptureChannel
{
    /**
     * Telegram's getFile serves files up to 20 MB. We stay well under it: a photo
     * larger than this buys no accuracy from Gemini and costs tokens linearly.
     */
    public const MAX_PHOTO_BYTES = 10 * 1024 * 1024;

    public function key(): string
    {
        return 'telegram';
    }

    /**
     * Resolve a file id to its bytes. Telegram needs two calls: getFile to learn
     * where the file lives, then a download from the file endpoint.
     *
     * A transport failure comes back as null, exactly like an unusable response:
     * `OutboundHttp::to()` passes `throw: false`, which suppresses a 4xx or 5xx
     * but not the `ConnectionException` raised when the call never completes at
     * all. Letting that escape turned a network blip into a failed job, and its
     * message carries the request URI — which is where the bot token lives.
     */
    public function fetchMedia(string $mediaReference): ?CapturedMedia
    {
        try {
            return $this->download($mediaReference);
        } catch (ConnectionException $e) {
            Log::error('❌ Telegram: no se pudo contactar a la API para bajar un archivo. '
                .Redact::secrets($e->getMessage()));

            return null;
        }
    }

    private function download(string $mediaReference): ?CapturedMedia
    {
        $token = $this->botToken();

        $response = OutboundHttp::to('telegram')->get("https://api.telegram.org/bot{$token}/getFile", [
            'file_id' => $mediaReference,
        ]);

        $path = $response->successful() ? $response->json('result.file_path') : null;

        if (! is_string($path) || $path === '') {
            Log::error("❌ Telegram: no se pudo resolver el archivo {$mediaReference}.");

            return null;
        }

        $download = OutboundHttp::to('telegram')->get("https://api.telegram.org/file/bot{$token}/{$path}");

        if (! $download->successful() || $download->body() === '') {
            // Un 200 con cuerpo vacio no se distingue de un archivo real vacio, y
            // seguir adelante mandaria cero bytes a Gemini: se trata como fallo de
            // descarga, que es lo que es.
            Log::error("❌ Telegram: fallo la descarga de {$mediaReference}.");

            return null;
        }

        return new CapturedMedia($download->body(), $this->mimeTypeFor($path));
    }

    public function reply(string $externalId, string $text): void
    {
        $this->send([
            'chat_id' => $externalId,
            'text' => $text,
        ]);
    }

    /**
     * The same reply, with buttons attached.
     *
     * Deliberately NOT part of the {@see CaptureChannel} port. The port has three
     * methods because only three responsibilities are genuinely per-channel, and
     * buttons are not one of them — WhatsApp expresses the same intent through
     * Meta-approved interactive templates, on completely different terms. Widening
     * the interface would force a shape onto a channel that does not have it, and
     * would let a caller pass a Telegram keyboard to an adapter that cannot honour
     * it. Whoever wants buttons asks Telegram for them, by name.
     *
     * `$keyboard` is Telegram's `inline_keyboard`: rows of buttons, each carrying
     * a `callback_data` of at most 64 bytes. See {@see \App\Enums\BotMenuAction}.
     *
     * @param  array<int, array<int, array<string, string>>>  $keyboard
     */
    public function replyWithKeyboard(string $externalId, string $text, array $keyboard): void
    {
        $this->send([
            'chat_id' => $externalId,
            'text' => $text,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    /**
     * Closes a callback query.
     *
     * Not optional and not cosmetic: Telegram clients render a progress indicator
     * on the tapped button until this call arrives, so skipping it leaves the
     * button spinning forever in the user's chat. It is called even when the
     * answer itself fails, which is why it takes no text — the real answer travels
     * as a normal message.
     */
    public function answerCallback(string $callbackQueryId): void
    {
        $token = $this->botToken();

        try {
            $response = OutboundHttp::to('telegram_send')->post(
                "https://api.telegram.org/bot{$token}/answerCallbackQuery",
                ['callback_query_id' => $callbackQueryId],
            );
        } catch (ConnectionException $e) {
            Log::error('❌ Telegram: no se pudo contactar a la API para cerrar el callback. '
                .Redact::secrets($e->getMessage()));

            return;
        }

        if (! $response->successful()) {
            // Se registra y se sigue. El unico dano es el indicador girando en el
            // cliente, y hacer fallar el job por eso volveria a mandar la respuesta
            // entera al reintentar.
            Log::error('❌ Telegram: no se pudo cerrar el callback. '.$response->body());
        }
    }

    /**
     * Publishes the bot's command list, so Telegram renders it.
     *
     * This is what makes the menu findable. Everything else in this class answers
     * someone who already started a conversation; `setMyCommands` is the only call
     * that changes what the client shows before anyone types — the `/` list and the
     * labelled Menu button beside the input field.
     *
     * It replaces the whole list rather than appending, so it is idempotent and
     * safe to run on every deploy. Removing a case from {@see \App\Enums\BotCommand}
     * and re-running is how a command is retired.
     *
     * Unlike every other method here it **reports** its failure instead of
     * swallowing it. The others are called from a queued job answering a user, where
     * failing loudly means either a retry that duplicates a message or a job that
     * dies with the sender hearing nothing. This one is called from a console
     * command with an operator watching the exit code, and a registration that
     * silently did not happen is a menu nobody can find — the exact failure this
     * method exists to prevent.
     *
     * Sent through the write profile. `setMyCommands` is idempotent and could
     * afford the read profile's extra retry, but nothing here is waiting on it: a
     * human sees the result and runs it again. Keeping "writes go through
     * `telegram_send`" true without exceptions is worth more than one retry.
     *
     * @param  array<int, array<string, string>>  $commands  See {@see \App\Enums\BotCommand::registration()}.
     * @return bool Whether Telegram accepted the list.
     */
    public function registerCommands(array $commands): bool
    {
        $token = $this->botToken();

        try {
            $response = OutboundHttp::to('telegram_send')->post(
                "https://api.telegram.org/bot{$token}/setMyCommands",
                ['commands' => $commands],
            );
        } catch (ConnectionException $e) {
            Log::error('❌ Telegram: no se pudo contactar a la API para publicar los comandos. '
                .Redact::secrets($e->getMessage()));

            return false;
        }

        if (! $response->successful()) {
            Log::error('❌ Telegram: rechazó la lista de comandos. '.$response->body());

            return false;
        }

        return true;
    }

    /**
     * The one place an outbound `sendMessage` is built.
     *
     * Both {@see reply()} and {@see replyWithKeyboard()} owe the same two
     * guarantees — a connection that never completes must not escape as an
     * exception carrying the bot token in its URI, and a non-2xx must not fail the
     * caller's job — and having written them twice is how they drift apart.
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): void
    {
        $token = $this->botToken();

        // Perfil aparte del de lectura: sendMessage no es idempotente, asi que
        // reintenta una sola vez. El intercambio esta anotado en config/http.php.
        try {
            $response = OutboundHttp::to('telegram_send')->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        } catch (ConnectionException $e) {
            // Mismo contrato que el fallo por status, y por la misma razon. El
            // guard de abajo solo se alcanza cuando existe una respuesta, asi que
            // una conexion que nunca se completa lo esquivaba y hacia fallar el
            // job con la transaccion ya guardada — justo lo que el guard existe
            // para impedir. El mensaje se sanea porque la URI trae el bot token.
            Log::error('❌ Telegram: no se pudo contactar a la API para responder. '
                .Redact::secrets($e->getMessage()));

            return;
        }

        if (! $response->successful()) {
            // No se propaga: la transaccion ya quedo guardada y hacer fallar el job
            // la duplicaria en el reintento.
            Log::error('❌ Telegram: no se pudo responder al remitente. '.$response->body());
        }
    }

    /**
     * Telegram sends a photo as an array of increasingly large renditions. Picking
     * the biggest one that we are willing to download matters: a thumbnail parses
     * without error and silently produces a worse reading, which is the failure
     * mode nobody notices.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return string|null The chosen file id.
     */
    public function largestPhotoUnder(array $variants): ?string
    {
        $usable = array_filter(
            $variants,
            fn ($v) => isset($v['file_id'])
                && is_string($v['file_id'])
                && (int) ($v['file_size'] ?? 0) <= self::MAX_PHOTO_BYTES
        );

        if ($usable === []) {
            return null;
        }

        usort($usable, fn ($a, $b) => (int) ($a['file_size'] ?? 0) <=> (int) ($b['file_size'] ?? 0));

        $chosen = end($usable);

        Log::info("🖼️ Telegram: variante elegida {$chosen['file_id']} de ".count($variants)
            .' disponible(s), '.((int) ($chosen['file_size'] ?? 0)).' bytes.');

        return $chosen['file_id'];
    }

    private function botToken(): string
    {
        return (string) config('services.telegram.bot_token');
    }

    /** Telegram does not report a mime type, so it is inferred from the stored path. */
    private function mimeTypeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'image/jpeg',
        };
    }
}
