<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramCapture;
use App\Services\Capture\ChannelUpdateDeduplicator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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
     * It always answers 200: a non-2xx makes Telegram redeliver, and a payload we
     * cannot use will not become usable on the second attempt.
     */
    public function receive(Request $request, ChannelUpdateDeduplicator $dedup): Response
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

            $this->dispatchUpdate($update);
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
    private function dispatchUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        $chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : null;

        if ($chatId === null) {
            // Se registro como procesado igual: un update sin chat no va a mejorar
            // si Telegram lo reintenta.
            Log::info('ℹ️ Telegram: update sin mensaje utilizable, se ignora.');

            return;
        }

        if (isset($message['photo']) && is_array($message['photo'])) {
            ProcessTelegramCapture::dispatch($chatId, null, $message['photo']);

            return;
        }

        if (isset($message['text']) && is_string($message['text'])) {
            ProcessTelegramCapture::dispatch($chatId, $message['text']);

            return;
        }

        Log::info("ℹ️ Telegram: tipo de mensaje no soportado del chat {$chatId}, se ignora.");
    }
}
