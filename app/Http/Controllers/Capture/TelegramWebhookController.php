<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Enums\BotCommand;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramCapture;
use App\Jobs\ProcessTelegramMenu;
use App\Services\Capture\ChannelLinkTokenRedeemer;
use App\Services\Capture\ChannelUpdateDeduplicator;
use App\Services\Capture\TelegramChannel;
use App\Support\Redact;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    /**
     * Telegram sends one update per delivery. The cap only exists so a forged body
     * cannot fan out into unbounded jobs, each of which would later cost a Gemini
     * call. Anything dropped is logged rather than silently discarded.
     */
    private const MAX_UPDATES_PER_DELIVERY = 100;

    /** Matches external_update_id in processed_channel_updates. */
    private const MAX_UPDATE_ID_LENGTH = 64;

    /**
     * Accepts a delivery and enqueues the work.
     *
     * The secret is already verified by middleware, so anything reaching here is
     * from Telegram. Two things happen before dispatch and both matter:
     *
     * - Deduplication, so a redelivery costs one index lookup instead of a Gemini
     *   call. Telegram retries until it gets a 200, and a slow response is enough.
     * - Iteration over every update, so a batched delivery does not silently drop
     *   all but the first.
     *
     * It answers 200 for everything it managed to decide about — including a
     * payload it cannot use, which will not become usable on the second attempt,
     * so making Telegram redeliver it buys nothing.
     *
     * The one case that does earn a non-2xx is failing to hand the work off at
     * all. That is not a verdict on the delivery, it is the absence of one, and
     * it is temporary by nature: the queue comes back. Answering 200 there would
     * combine with the claim above to lose the movement for good — the claim
     * refuses the redelivery, and nothing else is holding the work.
     */
    public function receive(Request $request, ChannelUpdateDeduplicator $dedup, TelegramChannel $telegram): Response
    {
        $updates = $this->updatesIn($request->all());

        if (count($updates) > self::MAX_UPDATES_PER_DELIVERY) {
            Log::warning('⚠️ Telegram: entrega con '.count($updates).' updates; se procesan los primeros '
                .self::MAX_UPDATES_PER_DELIVERY.' y se descarta el resto.');

            $updates = array_slice($updates, 0, self::MAX_UPDATES_PER_DELIVERY);
        }

        foreach ($updates as $update) {
            $updateId = (string) ($update['update_id'] ?? '');

            if (strlen($updateId) > self::MAX_UPDATE_ID_LENGTH) {
                // Telegram manda un entero. Algo mas largo que la columna solo puede
                // venir de un cuerpo forjado, y dejarlo llegar a la base cambiaria el
                // 200 de siempre por un 500.
                Log::warning('⚠️ Telegram: update_id mas largo que la columna, se descarta la entrega.');

                continue;
            }

            if (! $dedup->claim('telegram', $updateId)) {
                continue;
            }

            try {
                $this->dispatchUpdate($update, $telegram);
            } catch (Throwable $e) {
                // El claim prometia que alguien se hacia cargo, y nadie se hizo
                // cargo: se devuelve para que la reentrega no choque contra el
                // indice unico y se descarte.
                $dedup->release('telegram', $updateId);

                Log::error('❌ Telegram: no se pudo encolar la entrega, se pide la reentrega. '
                    .Redact::secrets($e->getMessage()));

                return response('Encolado no disponible', 500);
            }
        }

        return response('OK', 200);
    }

    /**
     * Telegram posts one update per request today. Accepting a list as well costs
     * nothing and means a batched delivery would not lose everything after the
     * first item.
     *
     * @param  array<mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function updatesIn(array $body): array
    {
        if (isset($body['update_id'])) {
            return [$body];
        }

        return array_values(array_filter(
            $body,
            fn ($item) => is_array($item) && isset($item['update_id'])
        ));
    }

    /** @param array<string, mixed> $update */
    private function dispatchUpdate(array $update, TelegramChannel $telegram): void
    {
        // Un boton no llega como mensaje sino como callback_query, con su propio
        // sobre y su propio chat. Va antes que todo lo demas porque el update no
        // trae `message` en el nivel de arriba y caeria en "tipo no soportado",
        // contestandole al usuario que no entiende su propio boton.
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->dispatchCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        $chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : null;

        if ($chatId === null) {
            // Se registro como procesado igual: un update sin chat no va a mejorar
            // si Telegram lo reintenta.
            Log::info('ℹ️ Telegram: update sin mensaje utilizable, se ignora.');

            return;
        }

        if (isset($message['photo']) && is_array($message['photo'])) {
            // Elegir entre las variantes es conocimiento de Telegram, y este
            // controlador ya es de Telegram. El job solo habla el puerto.
            $fileId = $telegram->largestPhotoUnder($message['photo']);

            if ($fileId === null) {
                Log::warning('ℹ️ Telegram: ninguna variante utilizable del chat '.Redact::id($chatId).'.');
                ProcessTelegramCapture::dispatch($chatId, null, null, true);

                return;
            }

            ProcessTelegramCapture::dispatch($chatId, null, $fileId);

            return;
        }

        if (isset($message['text']) && is_string($message['text'])) {
            // El menu se separa antes de la captura, no dentro de ella. Todo texto
            // que no sea un comando se manda a Gemini como si fuera un gasto, asi
            // que un `/menu` que llegara ahi se cobraria como una lectura y se
            // contestaria como un comprobante ilegible.
            //
            // `/start` pelado entra por la misma puerta, y ahi esta el arreglo: no
            // empieza con `'/start '` (con espacio), asi que no era un intento de
            // vinculacion y caia en el parseo de texto. Un usuario ya vinculado que
            // aprieta el boton START que Telegram le pone en grande recibia, a cambio
            // de una lectura de Gemini, que su saludo no parece un movimiento con
            // monto. Ahora recibe el menu, que es lo que ese boton deberia haber
            // hecho siempre. `/start <token>` sigue yendo a captura: `opensMenu()`
            // compara el texto entero, nunca el prefijo.
            if (BotCommand::opensMenu($message['text'])) {
                ProcessTelegramMenu::dispatch($chatId);

                return;
            }

            ProcessTelegramCapture::dispatch($chatId, $this->withHashedLinkToken($message['text']));

            return;
        }

        // No se ignora en silencio. La entrega ya quedo reclamada y Telegram no la
        // va a reenviar, asi que si no se contesta aca el remitente no recibe
        // absolutamente nada — y todas las demas ramas de fallo si contestan, con
        // lo cual el silencio se lee como que el bot esta caido.
        Log::info('ℹ️ Telegram: tipo de mensaje no soportado del chat '.Redact::id($chatId).'.');
        ProcessTelegramCapture::dispatch($chatId, null, null, true);
    }

    /**
     * Hands a pressed button to the menu job.
     *
     * The chat id is read from `callback_query.message.chat.id` — the chat the
     * button is sitting in — and not from `callback_query.from.id`. They are the
     * same value in a private chat, which is the only place this bot operates, but
     * they answer different questions: `from` is who tapped, `message.chat` is
     * where the answer belongs. Using `from` would work today and quietly send the
     * reply to the wrong place the day the bot is ever added to a group.
     *
     * `callback_query.id` is carried through because Telegram spins an indicator
     * on the button until `answerCallbackQuery` closes it. A callback with no id
     * is not something Telegram sends; if one arrives, the answer is still worth
     * sending, so the job takes null rather than dropping the press.
     *
     * @param  array<string, mixed>  $callback
     */
    private function dispatchCallback(array $callback): void
    {
        $chatId = isset($callback['message']['chat']['id'])
            ? (string) $callback['message']['chat']['id']
            : null;

        if ($chatId === null) {
            Log::info('ℹ️ Telegram: callback sin chat utilizable, se ignora.');

            return;
        }

        $callbackQueryId = isset($callback['id']) ? (string) $callback['id'] : null;
        $data = isset($callback['data']) && is_string($callback['data']) ? $callback['data'] : null;

        ProcessTelegramMenu::dispatch($chatId, $callbackQueryId, $data);
    }

    /**
     * The same message with a `/start` link token replaced by its digest.
     *
     * A dispatched job's arguments are serialised into the queue payload, which
     * is not a pipe but a store: it sits in Redis, and the moment anything
     * throws before redemption it is copied into `failed_jobs.payload`, which
     * has no expiry and which Horizon renders in the browser. Sending the token
     * verbatim therefore reintroduced, one layer down, exactly the plaintext
     * that `channel_link_tokens` avoids by storing only `token_hash`.
     *
     * Hashing here rather than in the job is the whole point: the job's own
     * arguments are what gets stored, so it is already too late by then.
     */
    private function withHashedLinkToken(string $text): string
    {
        if (! str_starts_with($text, ProcessTelegramCapture::LINK_PREFIX)) {
            return $text;
        }

        $token = trim(substr($text, strlen(ProcessTelegramCapture::LINK_PREFIX)));

        return ProcessTelegramCapture::LINK_PREFIX.ChannelLinkTokenRedeemer::hash($token);
    }
}
