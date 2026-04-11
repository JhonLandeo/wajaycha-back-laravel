<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\OcrService;
use App\Services\TransactionAnalyzer;
use App\Services\CategorizationService;
use App\Models\Transaction;
use App\Models\Detail;
use App\Models\User; // Asumiendo que usas este modelo
use Carbon\Carbon;

class ProcessWhatsAppImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $imageId;
    protected $from;

    public function __construct($imageId, $from)
    {
        $this->imageId = $imageId;
        $this->from = $from; // Este es el número de WhatsApp
    }

    public function handle(OcrService $ocrService, TransactionAnalyzer $transactionAnalyzer, CategorizationService $categorizationService)
    {
        // ---------------------------------------------------------
        // 0. IDENTIFICAR AL USUARIO
        // ---------------------------------------------------------
        // Como es tu VPS personal, asumiremos el usuario 1. 
        // A futuro, puedes buscarlo por el número de WhatsApp: User::where('phone', $this->from)->first();
        $userId = 1;

        $whatsappToken = config('services.whatsapp.access_token');
        Log::info("WhatsApp Token: " . $whatsappToken);

        // ---------------------------------------------------------
        // WHATSAPP_ACCESS_TOKEN=EAAMbm7QZA8UUBRAQIZACFRvxpL1. DESCARGAR IMAGEN DESDE META
        // ---------------------------------------------------------
        $mediaResponse = Http::withToken($whatsappToken)
            ->get("https://graph.facebook.com/v21.0/{$this->imageId}");

        $mediaUrl = $mediaResponse->json('url');

        if (!$mediaUrl) {
            Log::error("❌ WhatsApp: No se pudo obtener la URL de la imagen. Respuesta: " . $mediaResponse->body());
            return;
        }

        $imageBytes = Http::withToken($whatsappToken)->get($mediaUrl)->body();
        Log::info("⬇️ Imagen descargada de Meta exitosamente.");

        // ---------------------------------------------------------
        // 2. EXTRAER TEXTO (AWS TEXTRACT)
        // ---------------------------------------------------------
        $rawText = $ocrService->getTextFromImage($imageBytes);

        if (!$rawText) {
            Log::error("❌ AWS Textract: No se detectó texto en la imagen.");
            return;
        }

        // ---------------------------------------------------------
        // 3. ESTRUCTURAR CON GEMINI AI
        // ---------------------------------------------------------
        $geminiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$geminiKey}";

        // Ajusté el prompt para que extraiga fechas en formato compatible con Carbon
        $systemPrompt = '
            Eres un asistente financiero. Extrae los datos del recibo (Yape/Plin) en JSON válido.
            - Si dice "¡Yapeaste!" o "Pago a", origin es "Jhon", destination es la contraparte, type_transaction es "expense".
            - Si dice "Te yapeó", destination es "Jhon", origin es quien paga, type_transaction es "income".
            Estructura estricta:
            {
              "amount": (decimal, ej: 2.50),
              "destination": (string),
              "origin": (string),
              "date_operation": (string, formato "YYYY-MM-DD HH:mm:ss", deduce el año actual si no está),
              "type_transaction": ("income" o "expense"),
              "message": (string, lo que haya en motivo o mensaje. Si no hay, pon vacío "")
            }';

        $response = Http::post($url, [
            'contents' => [['parts' => [['text' => $systemPrompt . "\n\nTexto: \"" . $rawText . "\""]]]],
            'generationConfig' => ['responseMimeType' => 'application/json']
        ]);

        if (!$response->successful()) {
            Log::error("❌ Error de Gemini: " . $response->body());
            return;
        }

        $data = $response->json();
        $jsonString = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($jsonString) {
            $td = json_decode($jsonString, true);

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
        }
    }

    /**
     * Busca un detalle existente usando Trigramas sobre la entidad limpia (Igual que en Excel)
     */
    private function findExistingDetail(int $userId, string $cleanEntity)
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
