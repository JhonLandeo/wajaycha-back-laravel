<?php

namespace App\Jobs;

use App\Support\Redact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Asumiendo que usas este modelo

class ProcessWhatsAppImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $imageId;

    protected string $from;

    public function __construct(string $imageId, string $from)
    {
        $this->imageId = $imageId;
        $this->from = $from; // Este es el número de WhatsApp
    }

    public function handle(
        \App\Services\AI\GeminiVisionService $geminiService,
        \App\Services\Capture\CaptureChannelRegistry $channels,
        \App\Services\Capture\ChannelIdentityResolver $identities,
        \App\Actions\Capture\RegisterCapturedTransactionAction $registerAction
    ): void {
        $channel = $channels->for('whatsapp');

        // 1. IDENTIFICAR AL USUARIO POR SU IDENTIDAD DE CANAL
        $user = $identities->resolve($channel->key(), $this->from);

        if (! $user) {
            Log::warning('❌ WhatsApp: Número no registrado ('.Redact::id($this->from).').');
            $channel->reply($this->from, '❌ Tu número de WhatsApp no está vinculado a ninguna cuenta. Por favor, actualiza tu perfil en la app.');

            return;
        }

        // 2. DESCARGAR IMAGEN POR EL PUERTO DE CAPTURA
        $media = $channel->fetchMedia($this->imageId);

        if (! $media) {
            $channel->reply($this->from, '❌ Error al descargar el comprobante de Meta. Por favor, contacte con soporte si el problema persiste.');

            return;
        }

        // 3. ANALIZAR IMAGEN CON GEMINI
        $parsedReceipt = $geminiService->parseReceipt($media->bytes, $media->mimeType);

        if (! $parsedReceipt) {
            $channel->reply($this->from, '❌ Error de conexión con el motor de IA. Por favor, contacte con soporte si el problema persiste.');

            return;
        }

        if (! $parsedReceipt->isValid) {
            $channel->reply($this->from, '❌ La imagen enviada no parece ser un comprobante de pago válido.');

            return;
        }

        // 4. REGISTRAR TRANSACCIÓN (ORQUESTACIÓN)
        $transaction = $registerAction->execute($user, $parsedReceipt);

        // 5. NOTIFICAR ÉXITO
        $description = $parsedReceipt->type === 'expense' ? $parsedReceipt->destination : $parsedReceipt->origin;
        $channel->reply($this->from, '✅ Comprobante registrado: S/ '.number_format($parsedReceipt->amount, 2)." a {$description}.");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('❌ Job ProcessWhatsAppImage falló inesperadamente: '.$exception->getMessage());

        try {
            $channel = app(\App\Services\Capture\CaptureChannelRegistry::class)->for('whatsapp');
            $channel->reply($this->from, '❌ Ocurrió un error inesperado al procesar tu comprobante. Por favor, contacta con soporte técnico.');
        } catch (\Exception $e) {
            Log::error('❌ No se pudo enviar el mensaje de fallo al usuario (en failed): '.$e->getMessage());
        }
    }
}
