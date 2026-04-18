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

    public function handle(TransactionAnalyzer $transactionAnalyzer, CategorizationService $categorizationService, \App\Services\WhatsAppNotificationService $whatsappService): void
    {
        $user = User::where('whatsapp_phone', $this->from)->first();
        
        if (!$user) {
            Log::warning("❌ WhatsApp Text: Número no registrado ({$this->from}).");
            $whatsappService->sendTextMessage($this->from, "❌ Tu número de WhatsApp no está vinculado a ninguna cuenta. Por favor, actualiza tu perfil en la app.");
            return;
        }

        $userId = $user->id;

        $apiKey = config('services.gemini.api_key');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        $systemPrompt = '
            Eres un asistente financiero avanzado. Lee la narración o el comando de texto del usuario sobre un gasto o ingreso y extrae los datos en un JSON válido.
            - Si el usuario dice que gastó, pagó, compró o yapeó, es un "expense". El "origin" es el "Usuario" y "destination" es la entidad (tienda, empresa, negocio o persona a la que se le paga).
            - Si el usuario dice que recibió, cobró o le pagaron (ej: "me pagaron", "me yapearon"), es un "income". El "destination" es el "Usuario" y "origin" es la entidad que le envía el dinero. Deduce su nombre o perfil (ej: "Cliente", "Alumno", "Familiar"). NUNCA respondas literalmente la palabra "contraparte".
            - IMPORTANTE: Si el mensaje es ambiguo, le falta el monto, no describe una transacción financiera, o es un saludo u otro tema, asume "is_valid_transaction" en false.
            Estructura estricta:
            {
              "is_valid_transaction": (boolean, false si no es una operación financiera válida o falta monto, true si es válida),
              "amount": (decimal, ej: 2.50. Siempre numérico positivo),
              "destination": (string),
              "origin": (string),
              "date_operation": (string, formato "YYYY-MM-DD HH:mm:ss", deduce según la fecha actual si dice "hoy" o "ayer", si no dice nada usa hoy),
              "type_transaction": ("income" o "expense"),
              "message": (string, motivo adicional mencionado, ejemplo: almuerzo, cine, etc. Si no aplica, pon "")
            }
        ';

        $finalPrompt = $systemPrompt . "\n\nFecha actual de referencia: " . Carbon::now()->toDateTimeString() . "\n\nMensaje enviado por el usuario: \"" . $this->text . "\"";

        $response = Http::post($url, [
            'contents' => [
                ['parts' => [['text' => $finalPrompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ]);

        if (!$response->successful()) {
            Log::error("❌ Error de Gemini: " . $response->body());
            $whatsappService->sendTextMessage($this->from, "❌ Error de conexión al procesar el mensaje. Por favor, contacte con soporte si el problema persiste.");
            return;
        }

        $data = $response->json();
        $jsonString = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($jsonString) {
            $td = json_decode($jsonString, true);

            // Verificamos si no es una transacción válida
            if (isset($td['is_valid_transaction']) && $td['is_valid_transaction'] === false) {
                $whatsappService->sendTextMessage($this->from, "❌ El mensaje que enviaste no parece indicar un registro financiero claro o no especificaste el monto. Intenta de nuevo.");
                return;
            }

            // ---------------------------------------------------------
            // LÓGICA DE DETALLES Y CATEGORIZACIÓN (Igual a ProcessWhatsAppImage)
            // ---------------------------------------------------------
            $isExpense = $td['type_transaction'] === 'expense';
            $descriptionRaw = $isExpense ? ($td['destination'] ?? '') : ($td['origin'] ?? '');

            if (empty(trim($descriptionRaw)) || strtolower($descriptionRaw) === 'usuario') {
                $descriptionRaw = "Desconocido WhatsApp";
            }

            // A. Analizamos para obtener la entidad limpia
            $features = $transactionAnalyzer->analyze($descriptionRaw);
            $cleanEntity = $features['entity'];

            // B. Buscamos el detalle INTELIGENTEMENTE con Trigramas
            $detail = $this->findExistingDetail($userId, $cleanEntity);

            // C. Si no existe, lo creamos
            if (!$detail) {
                $detail = Detail::create([
                    'user_id' => $userId,
                    'description' => $descriptionRaw,
                    'operation_type' => $features['type'] ?? 'unknown',
                    'entity_clean' => $cleanEntity
                ]);
                Log::info("🆕 WhatsApp Texto: Nuevo Detalle creado: {$descriptionRaw} (Clean: {$cleanEntity})");
            } else {
                if (empty($detail->entity_clean)) {
                    $detail->update(['entity_clean' => $cleanEntity]);
                }
            }

            // D. Categorizamos usando el servicio
            $categoryId = $categorizationService->findCategory(
                $userId,
                $detail,
                $td['message'] ?? ''
            );

            // ---------------------------------------------------------
            // GUARDAR TRANSACCIÓN FINAL
            // ---------------------------------------------------------
            $dateOp = (isset($td['date_operation']) && !empty($td['date_operation'])) 
                        ? Carbon::parse($td['date_operation'])->format('Y-m-d H:i:s') 
                        : Carbon::now()->format('Y-m-d H:i:s');

            Transaction::create([
                'user_id' => $userId,
                'detail_id' => $detail->id,
                'category_id' => $categoryId,
                'amount' => (float) $td['amount'],
                'type_transaction' => $td['type_transaction'],
                'date_operation' => $dateOp,
                'is_manual' => true,
            ]);

            Log::info("✅ Transacción de texto guardada: S/ {$td['amount']} -> Detalle: {$descriptionRaw} / Categoría: {$categoryId}");
            $whatsappService->sendTextMessage($this->from, "✅ Registro exitoso: S/ " . number_format($td['amount'], 2) . ($isExpense ? " pagado a " : " recibido de ") . $descriptionRaw . ".");
        } else {
            Log::error("❌ No se generó un JSON válido para: " . $this->text);
            $whatsappService->sendTextMessage($this->from, "❌ No pude procesar los datos de tu mensaje. Intenta ser más claro con el formato.");
        }
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

    /**
     * Busca un detalle existente usando Trigramas sobre la entidad limpia
     */
    private function findExistingDetail(int $userId, string $cleanEntity): ?Detail
    {
        $threshold = 0.6;

        return Detail::where('user_id', $userId)
            ->where(function ($query) use ($cleanEntity, $threshold) {
                $query->where('entity_clean', $cleanEntity)
                    ->orWhereRaw('similarity(entity_clean, ?) > ?', [$cleanEntity, $threshold]);
            })
            ->orderByRaw('similarity(entity_clean, ?) DESC', [$cleanEntity])
            ->first();
    }
}
