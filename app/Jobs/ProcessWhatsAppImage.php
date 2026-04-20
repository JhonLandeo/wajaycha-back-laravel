<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\TransactionAnalyzer;
use App\Services\CategorizationService;
use App\Models\Transaction;
use App\Models\Detail;
use App\Models\User; // Asumiendo que usas este modelo
use Carbon\Carbon;

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
        \App\Services\WhatsApp\MetaMediaService $metaService,
        \App\Actions\WhatsApp\RegisterWhatsAppTransactionAction $registerAction,
        \App\Services\WhatsAppNotificationService $whatsappService
    ): void {
        // 1. IDENTIFICAR AL USUARIO
        $user = \App\Models\User::where('whatsapp_phone', $this->from)->first();

        if (!$user) {
            Log::warning("❌ WhatsApp: Número no registrado ({$this->from}).");
            $whatsappService->sendTextMessage($this->from, "❌ Tu número de WhatsApp no está vinculado a ninguna cuenta. Por favor, actualiza tu perfil en la app.");
            return;
        }

        // 2. DESCARGAR IMAGEN DESDE META
        $media = $metaService->downloadMedia($this->imageId);

        if (!$media) {
            $whatsappService->sendTextMessage($this->from, "❌ Error al descargar el comprobante de Meta. Por favor, contacte con soporte si el problema persiste.");
            return;
        }

        // 3. ANALIZAR IMAGEN CON GEMINI
        $parsedReceipt = $geminiService->parseReceipt($media['bytes'], $media['mimeType']);

        if (!$parsedReceipt) {
            $whatsappService->sendTextMessage($this->from, "❌ Error de conexión con el motor de IA. Por favor, contacte con soporte si el problema persiste.");
            return;
        }

        if (!$parsedReceipt->isValid) {
            $whatsappService->sendTextMessage($this->from, "❌ La imagen enviada no parece ser un comprobante de pago válido.");
            return;
        }

        // 4. REGISTRAR TRANSACCIÓN (ORQUESTACIÓN)
        $transaction = $registerAction->execute($user, $parsedReceipt);

        // 5. NOTIFICAR ÉXITO
        $description = $parsedReceipt->type === 'expense' ? $parsedReceipt->destination : $parsedReceipt->origin;
        $whatsappService->sendTextMessage($this->from, "✅ Comprobante registrado: S/ " . number_format($parsedReceipt->amount, 2) . " a {$description}.");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ Job ProcessWhatsAppImage falló inesperadamente: " . $exception->getMessage());

        try {
            $whatsappService = app(\App\Services\WhatsAppNotificationService::class);
            $whatsappService->sendTextMessage($this->from, "❌ Ocurrió un error inesperado al procesar tu comprobante. Por favor, contacta con soporte técnico.");
        } catch (\Exception $e) {
            Log::error("❌ No se pudo enviar el mensaje de fallo al usuario (en failed): " . $e->getMessage());
        }
    }
}
