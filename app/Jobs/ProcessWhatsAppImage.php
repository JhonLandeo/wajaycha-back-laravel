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

    public function handle(TransactionAnalyzer $transactionAnalyzer, CategorizationService $categorizationService, \App\Services\WhatsAppNotificationService $whatsappService): void
    {
        // ---------------------------------------------------------
        // 0. IDENTIFICAR AL USUARIO
        // ---------------------------------------------------------
        $user = User::where('whatsapp_phone', $this->from)->first();

        if (!$user) {
            Log::warning("❌ WhatsApp: Número no registrado ({$this->from}).");
            $whatsappService->sendTextMessage($this->from, "❌ Tu número de WhatsApp no está vinculado a ninguna cuenta. Por favor, actualiza tu perfil en la app.");
            return;
        }

        $userId = $user->id;

        $whatsappToken = config('services.whatsapp.access_token');
        Log::info("WhatsApp Token: " . $whatsappToken);

        // ---------------------------------------------------------
        // 1. DESCARGAR IMAGEN DESDE META
        // ---------------------------------------------------------
        $mediaResponse = Http::withToken($whatsappToken)
            ->get("https://graph.facebook.com/v21.0/{$this->imageId}");

        $mediaUrl = $mediaResponse->json('url');
        $mimeType = $mediaResponse->json('mime_type') ?? 'image/jpeg'; // Capturamos el mime type si viene, si no asume jpeg

        if (!$mediaUrl) {
            Log::error("❌ WhatsApp: No se pudo obtener la URL de la imagen. Respuesta: " . $mediaResponse->body());
            $whatsappService->sendTextMessage($this->from, "❌ Error al descargar el comprobante de Meta. Por favor, contacte con soporte si el problema persiste.");
            return;
        }

        $imageBytes = Http::withToken($whatsappToken)->get($mediaUrl)->body();
        Log::info("⬇️ Imagen descargada de Meta exitosamente.");

        $base64Image = base64_encode($imageBytes);

        // ---------------------------------------------------------
        // 2 & 3. ANALIZAR IMAGEN DIRECTAMENTE CON GEMINI AI VISON
        // ---------------------------------------------------------
        $geminiKey = config('services.gemini.api_key');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$geminiKey}";

        $systemPrompt = '
            Eres un asistente financiero. Lee y extrae los datos del recibo o comprobante (Yape/Plin, etc.) de la imagen adjunta en un JSON válido.
            - Si dice "¡Yapeaste!" o "Pago a", origin es el "Usuario", destination es a quién le paga.
            - Si dice "Te yapeó", destination es el "Usuario", origin es quien paga. Deduce o extrae el nombre exacto de la persona o negocio. NUNCA uses la palabra literal "contraparte".
            - IMPORTANTE: Si evalúas la imagen y determinas que NO ES un comprobante de pago o transferencia financiera válida (por ejemplo, es un meme, un chat, una foto aleatoria), debes devolver el JSON con "is_valid_receipt" en false.
            Estructura estricta:
            {
              "is_valid_receipt": (boolean, false si no parece ser un comprobante de pago válido, true si sí lo es),
              "amount": (decimal, ej: 2.50),
              "destination": (string),
              "origin": (string),
              "date_operation": (string, formato "YYYY-MM-DD HH:mm:ss", deduce el año actual si no está),
              "type_transaction": ("income" o "expense"),
              "message": (string, lo que haya en motivo o mensaje. Si no hay, pon vacío "")
            }';

        $response = Http::post($url, [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data' => $base64Image
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => ['responseMimeType' => 'application/json']
        ]);

        if (!$response->successful()) {
            Log::error("❌ Error de Gemini: " . $response->body());
            $whatsappService->sendTextMessage($this->from, "❌ Error de conexión con el motor de IA. Por favor, contacte con soporte si el problema persiste.");
            return;
        }

        $data = $response->json();
        $jsonString = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($jsonString) {
            $td = json_decode($jsonString, true);

            // Verificamos si no es un comprobante válido según Gemini
            if (isset($td['is_valid_receipt']) && $td['is_valid_receipt'] === false) {
                $whatsappService->sendTextMessage($this->from, "❌ La imagen enviada no parece ser un comprobante de pago válido.");
                return;
            }

            // ---------------------------------------------------------
            // 4. LÓGICA DE DETALLES Y CATEGORIZACIÓN (ESTILO EXCEL)
            // ---------------------------------------------------------
            $isExpense = $td['type_transaction'] === 'expense';
            $descriptionRaw = $isExpense ? $td['destination'] : $td['origin'];

            // Si Gemini no capturó la contraparte, ponemos un fallback seguro
            if (empty($descriptionRaw)) $descriptionRaw = "Desconocido WhatsApp";

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
                Log::info("🆕 WhatsApp: Nuevo Detalle creado: {$descriptionRaw} (Clean: {$cleanEntity})");
            } else {
                if (empty($detail->entity_clean)) {
                    $detail->update(['entity_clean' => $cleanEntity]);
                }
            }

            // D. Categorizamos usando el servicio
            $categoryId = $categorizationService->findCategory(
                $userId,
                $detail,
                $td['message']
            );

            // ---------------------------------------------------------
            // 5. GUARDAR TRANSACCIÓN FINAL
            // ---------------------------------------------------------
            // Parseamos la fecha para asegurarnos de que Carbon la acepte
            $dateOp = Carbon::parse($td['date_operation'])->format('Y-m-d H:i:s');

            Transaction::create([
                'user_id' => $userId,
                'detail_id' => $detail->id,
                'category_id' => $categoryId,
                'amount' => (float) $td['amount'],
                'type_transaction' => $td['type_transaction'],
                'date_operation' => $dateOp,
                'message' => $td['message'],
            ]);

            Log::info("✅ Yape procesado (S/ {$td['amount']} a {$descriptionRaw}) -> Categoría ID: {$categoryId}");
            $whatsappService->sendTextMessage($this->from, "✅ Comprobante registrado: S/ " . number_format($td['amount'], 2) . " a {$descriptionRaw}.");
        } else {
            $whatsappService->sendTextMessage($this->from, "❌ No pude extraer datos válidos del comprobante. Por favor, contacte con soporte.");
        }
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

    /**
     * Busca un detalle existente usando Trigramas sobre la entidad limpia (Igual que en Excel)
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
