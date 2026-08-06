<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Capture\RegisterCapturedTransactionAction;
use App\Services\AI\GeminiTextService;
use App\Services\AI\GeminiVisionService;
use App\Services\Capture\CaptureChannel;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\ChannelLinkTokenRedeemer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one Telegram message into a Transaction, or into the reply that explains
 * why it did not become one.
 *
 * Everything below the transport is the shared pipeline: the same Gemini services,
 * the same ParsedReceiptDTO, the same register action WhatsApp uses.
 */
class ProcessTelegramCapture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const LINK_PREFIX = '/start ';

    /**
     * @param  string|null  $mediaReference  The already-chosen file id, if this was a photo.
     */
    public function __construct(
        private readonly string $chatId,
        private readonly ?string $text = null,
        private readonly ?string $mediaReference = null,
    ) {}

    public function handle(
        CaptureChannelRegistry $channels,
        ChannelIdentityResolver $identities,
        ChannelLinkTokenRedeemer $redeemer,
        GeminiVisionService $vision,
        GeminiTextService $textService,
        RegisterCapturedTransactionAction $registerAction,
    ): void {
        $channel = $channels->for('telegram');

        // 1. VINCULACION: /start <token> llega antes que cualquier identidad, porque
        //    es justamente lo que la crea.
        if ($this->isLinkAttempt()) {
            $this->link($channel, $redeemer);

            return;
        }

        // 2. IDENTIFICAR AL REMITENTE POR SU IDENTIDAD DE CANAL
        $user = $identities->resolve($channel->key(), $this->chatId);

        if (! $user) {
            Log::warning("❌ Telegram: chat {$this->chatId} sin vincular.");
            $channel->reply($this->chatId, '❌ Tu cuenta de Telegram no está vinculada. Abre la app y genera tu enlace de vinculación.');

            return;
        }

        // 3. PARSEAR: foto o texto, mismo destino
        $parsed = $this->mediaReference !== null
            ? $this->parsePhoto($channel, $vision)
            : $textService->parseText((string) $this->text);

        if (! $parsed) {
            $channel->reply($this->chatId, '❌ No pude leer ese envío. Intenta de nuevo o contacta con soporte si el problema persiste.');

            return;
        }

        if (! $parsed->isValid) {
            $channel->reply($this->chatId, '❌ Eso no parece un movimiento con monto. Envíame un comprobante o dime cuánto gastaste.');

            return;
        }

        // 4. REGISTRAR (ORQUESTACION)
        $registerAction->execute($user, $parsed);

        // 5. CONFIRMAR
        $isExpense = $parsed->type === 'expense';
        $description = $isExpense ? $parsed->destination : $parsed->origin;
        $channel->reply(
            $this->chatId,
            '✅ Registrado: S/ '.number_format($parsed->amount, 2)
                .($isExpense ? ' pagado a ' : ' recibido de ').$description.'.'
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('❌ Job ProcessTelegramCapture falló inesperadamente: '.$exception->getMessage());

        try {
            app(CaptureChannelRegistry::class)
                ->for('telegram')
                ->reply($this->chatId, '❌ Ocurrió un error inesperado al procesar tu envío. Intenta de nuevo o contacta con soporte.');
        } catch (Throwable $e) {
            Log::error('❌ No se pudo avisar del fallo al remitente: '.$e->getMessage());
        }
    }

    private function isLinkAttempt(): bool
    {
        return $this->text !== null && str_starts_with($this->text, self::LINK_PREFIX);
    }

    private function link(CaptureChannel $channel, ChannelLinkTokenRedeemer $redeemer): void
    {
        $token = trim(substr((string) $this->text, strlen(self::LINK_PREFIX)));

        if ($redeemer->redeem($channel->key(), $this->chatId, $token)) {
            $channel->reply($this->chatId, '✅ Cuenta vinculada. Ya puedes enviarme tus comprobantes.');

            return;
        }

        // Una sola respuesta para todo rechazo. Distinguir "vencido" de "inexistente"
        // le diria a un curioso que tokens son reales.
        $channel->reply($this->chatId, '❌ Ese enlace no es válido o ya fue usado. Genera uno nuevo desde la app.');
    }

    private function parsePhoto(CaptureChannel $channel, GeminiVisionService $vision): mixed
    {
        $media = $channel->fetchMedia((string) $this->mediaReference);

        return $media ? $vision->parseReceipt($media->bytes, $media->mimeType) : null;
    }
}
