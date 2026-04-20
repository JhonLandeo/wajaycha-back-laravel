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
use App\Models\User;
use Carbon\Carbon;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $text;
    protected string $from;

    public function __construct(string $text, string $from)
    {
        $this->text = $text;
        $this->from = $from;
    }

    public function handle(
        \App\Services\AI\GeminiTextService $geminiService,
        \App\Actions\WhatsApp\RegisterWhatsAppTransactionAction $registerAction,
        \App\Services\WhatsAppNotificationService $whatsappService
    ): void {
        // 1. IDENTIFICAR AL USUARIO
        $user = \App\Models\User::where('whatsapp_phone', $this->from)->first();

        if (!$user) {
            Log::warning("❌ WhatsApp Text: Número no registrado ({$this->from}).");
            $whatsappService->sendTextMessage($this->from, "❌ Tu número de WhatsApp no está vinculado a ninguna cuenta. Por favor, actualiza tu perfil en la app.");
            return;
        }

        // 2. ANALIZAR TEXTO CON GEMINI
        $parsedReceipt = $geminiService->parseText($this->text);

        if (!$parsedReceipt) {
            $whatsappService->sendTextMessage($this->from, "❌ Error de conexión al procesar el mensaje. Por favor, contacte con soporte si el problema persiste.");
            return;
        }

        if (!$parsedReceipt->isValid) {
            $whatsappService->sendTextMessage($this->from, "❌ El mensaje que enviaste no parece indicar un registro financiero claro o no especificaste el monto. Intenta de nuevo.");
            return;
        }

        // 3. REGISTRAR TRANSACCIÓN (ORQUESTACIÓN)
        $transaction = $registerAction->execute($user, $parsedReceipt);

        // 4. NOTIFICAR ÉXITO
        $isExpense = $parsedReceipt->type === 'expense';
        $description = $isExpense ? $parsedReceipt->destination : $parsedReceipt->origin;
        $whatsappService->sendTextMessage($this->from, "✅ Registro exitoso: S/ " . number_format($parsedReceipt->amount, 2) . ($isExpense ? " pagado a " : " recibido de ") . $description . ".");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ Job ProcessWhatsAppMessage falló inesperadamente: " . $exception->getMessage());

        try {
            $whatsappService = app(\App\Services\WhatsAppNotificationService::class);
            $whatsappService->sendTextMessage($this->from, "❌ Ocurrió un error inesperado al procesar tu mensaje de texto. Por favor, intenta de nuevo o contacta con soporte técnico.");
        } catch (\Exception $e) {
            Log::error("❌ No se pudo enviar el mensaje de fallo al usuario (en failed message job): " . $e->getMessage());
        }
    }
}
